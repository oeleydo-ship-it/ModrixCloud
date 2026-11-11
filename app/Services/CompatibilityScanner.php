<?php

namespace Marketplace\Services;

use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Marketplace\Models\CoreRelease;
use Marketplace\Models\Package;
use Throwable;
use ZipArchive;

/**
 * Checks a submitted module or theme against what Modrix Commerce stores
 * accept (config/compatibility.php): the manifest's keys, capabilities and
 * platform_version, the files it points to, the storefront places and slots
 * its PHP code registers, and the packages it requires.
 *
 * It works out the oldest core the package can run on and refuses a
 * platform_version that would let older stores install it. Files are only
 * read as text, never extracted or run.
 */
class CompatibilityScanner
{
    private const MAX_PHP_FILES = 2000;

    private const MAX_PHP_BYTES = 512 * 1024;

    private const NAME = '/^[a-z0-9][a-z0-9-]*\/[a-z0-9][a-z0-9-]*$/';

    /** Files are read for the security scan up to this size. */
    private const MAX_SCAN_BYTES = 2 * 1024 * 1024;

    /** Files a store package never needs: other server-side scripts, server config and executables. */
    private const BLOCKED_FILES = '/\.(phtml|phar|php[3-8]|phps|pht|pgif|shtml|cgi|pl|py|rb|sh|bash|bat|cmd|ps1|vbs|exe|dll|so|dylib|com|jar|bin)$|(^|\/)(\.htaccess|\.user\.ini|php\.ini|web\.config)$/i';

    /** PHP functions that run shell commands or code built at run time: always rejected. */
    private const DANGEROUS_FUNCTIONS = [
        'exec' => 'runs shell commands', 'shell_exec' => 'runs shell commands', 'system' => 'runs shell commands',
        'passthru' => 'runs shell commands', 'proc_open' => 'runs shell commands', 'popen' => 'runs shell commands',
        'pcntl_exec' => 'runs programs', 'create_function' => 'runs code built at run time', 'dl' => 'loads PHP extensions',
    ];

    /** PHP functions with rare legitimate uses in a module: flagged for the reviewer. */
    private const RISKY_FUNCTIONS = [
        'fsockopen' => 'opens raw network sockets', 'pfsockopen' => 'opens raw network sockets',
        'stream_socket_client' => 'opens raw network sockets', 'stream_socket_server' => 'listens on a network port',
        'socket_create' => 'opens raw network sockets', 'gzinflate' => 'unpacks compressed data (often used to hide code)',
        'gzuncompress' => 'unpacks compressed data (often used to hide code)', 'gzdecode' => 'unpacks compressed data (often used to hide code)',
        'str_rot13' => 'decodes scrambled text (often used to hide code)', 'convert_uudecode' => 'decodes hidden data',
        'putenv' => 'changes the server environment', 'ignore_user_abort' => 'keeps running after the request ends',
        'pcntl_fork' => 'starts background processes', 'posix_kill' => 'signals other processes', 'symlink' => 'creates symbolic links',
    ];

    /** Names of well-known web shells and browser miners. */
    private const SIGNATURES = '/FilesMan|c99shell|r57shell|b374k|WSOsetcookie|IndoXploit|AnonymousFox|coinhive|coin-hive|cryptonight|webminepool|minero\.cc|crypto-loot|coinimp|jsecoin/i';

    /** @var list<array{level: string, message: string}> */
    private array $issues = [];

    /** @var array<string, string> feature => core version */
    private array $uses = [];

    /** @var array<string, true> */
    private array $names = [];

    public function __construct(private readonly CoreReleases $cores) {}

    /**
     * @param  string  $zipPath  a package ZIP with its manifest at the root (as Uploads stores it)
     * @param  string|null  $formPlatform  the platform version typed in the submission form, if any
     */
    public function scan(string $zipPath, string $type, ?string $formPlatform = null): CompatibilityReport
    {
        $this->issues = [];
        $this->uses = [];
        $this->names = [];
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            $this->error('The package is not a valid ZIP archive.');

            return $this->report(null, []);
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $this->names[str_replace('\\', '/', (string) $zip->getNameIndex($i))] = true;
            }

            // Runs first, so a package with a broken manifest is still checked for malicious code.
            $this->security($zip, $type);

            $manifestName = $type === 'theme' ? 'theme.json' : 'module.json';
            $manifest = json_decode((string) $zip->getFromName($manifestName), true);

            if (! is_array($manifest) || array_is_list($manifest)) {
                $this->error("{$manifestName} must be a JSON object.");

                return $this->report(null, []);
            }

            $this->manifest($manifest, $type, $manifestName);

            if ($type === 'theme') {
                $this->themeFiles($manifest);
            } else {
                $this->moduleFiles($manifest);
                $this->code($zip);
            }
        } finally {
            $zip->close();
        }

        $platform = $this->platform($manifest, $formPlatform);
        $requires = $this->requires($manifest['requires'] ?? []);

        return $this->report($platform, $requires);
    }

    /** @param  array<string, mixed>  $manifest */
    private function manifest(array $manifest, string $type, string $manifestName): void
    {
        $keys = (array) config($type === 'theme' ? 'compatibility.theme_keys' : 'compatibility.module_keys');

        foreach ($manifest as $key => $value) {
            if (! array_key_exists($key, $keys)) {
                $this->error("{$manifestName} has the key \"{$key}\", which Modrix Commerce does not recognise. Stores refuse packages with unknown keys; remove it.");
            } else {
                $this->use("manifest key \"{$key}\"", $keys[$key]);
            }
        }

        foreach (['name', 'display_name', 'version', 'platform_version'] as $required) {
            if (! is_string($manifest[$required] ?? null) || trim($manifest[$required]) === '') {
                $this->error("{$manifestName} must set \"{$required}\".");
            }
        }

        if (($manifest['type'] ?? null) !== $type) {
            $this->error("{$manifestName} must set \"type\": \"{$type}\".");
        }

        if ($type === 'theme' && ($manifest['theme_type'] ?? null) !== 'storefront') {
            $this->error('theme.json must set "theme_type": "storefront".');
        }

        $known = (array) config($type === 'theme' ? 'compatibility.theme_capabilities' : 'compatibility.module_capabilities');
        $capabilities = $manifest['capabilities'] ?? [];

        if (! is_array($capabilities) || ! array_is_list($capabilities)) {
            $this->error('"capabilities" must be a list.');

            return;
        }

        foreach ($capabilities as $capability) {
            if (! is_string($capability) || ! array_key_exists($capability, $known)) {
                $label = is_string($capability) ? $capability : gettype($capability);
                $this->error("Unknown capability \"{$label}\". Use one of: ".implode(', ', array_keys($known)).'.');
            } else {
                $this->use("capability \"{$capability}\"", $known[$capability]);
            }
        }
    }

    /** Files and folders a module manifest points to must be in the package. */
    private function moduleFiles(array $manifest): void
    {
        foreach ((array) ($manifest['routes'] ?? []) as $area => $file) {
            if (is_string($file) && ! $this->hasFile($file)) {
                $this->error("routes.{$area} points to {$file}, which is not in the package.");
            }
        }

        if (is_string($manifest['migrations'] ?? null) && ! $this->hasFolder($manifest['migrations'])) {
            $this->error("migrations points to {$manifest['migrations']}/, which is not in the package.");
        }

        foreach ((array) ($manifest['assets'] ?? []) as $kind => $folder) {
            if (is_string($folder) && ! $this->hasFolder($folder)) {
                $this->error("assets.{$kind} points to {$folder}/, which is not in the package.");
            }
        }

        $provider = $manifest['provider'] ?? null;

        if (! is_string($provider) || $provider === '') {
            return;
        }

        foreach ((array) ($manifest['autoload']['psr-4'] ?? []) as $namespace => $folder) {
            if (is_string($namespace) && is_string($folder) && $namespace !== '' && str_starts_with($provider, $namespace)) {
                $file = rtrim($folder, '/').'/'.str_replace('\\', '/', substr($provider, strlen($namespace))).'.php';

                if (! $this->hasFile($file)) {
                    $this->error("The provider {$provider} should be in {$file}, which is not in the package.");
                }

                return;
            }
        }

        $this->error("The provider {$provider} is not inside a namespace listed under autoload.psr-4.");
    }

    /** Files a theme manifest points to; missing ones are flagged for review. */
    private function themeFiles(array $manifest): void
    {
        $files = [
            'screenshot' => $manifest['screenshot'] ?? null,
            'customizer_file' => $manifest['customizer_file'] ?? null,
            'demo_content' => $manifest['demo_content'] ?? null,
            'frontend.stylesheet' => $manifest['frontend']['stylesheet'] ?? null,
            'frontend.layout' => $manifest['frontend']['layout'] ?? null,
        ];

        foreach ((array) ($manifest['fonts'] ?? []) as $i => $font) {
            $files["fonts.{$i}.file"] = is_array($font) ? ($font['file'] ?? null) : null;
        }

        foreach ($files as $key => $file) {
            if (is_string($file) && $file !== '' && ! $this->hasFile($file)) {
                $this->warning("{$key} points to {$file}, which is not in the package.");
            }
        }

        if (is_string($manifest['assets'] ?? null) && ! $this->hasFolder($manifest['assets'])) {
            $this->warning("assets points to {$manifest['assets']}/, which is not in the package.");
        }
    }

    /** Storefront places and slots the module's PHP code registers. */
    private function code(ZipArchive $zip): void
    {
        $places = (array) config('compatibility.places');
        $slots = (array) config('compatibility.slots');
        $read = 0;

        foreach (array_keys($this->names) as $name) {
            if (! str_ends_with($name, '.php') || str_starts_with($name, 'vendor/') || ++$read > self::MAX_PHP_FILES) {
                continue;
            }

            $stat = $zip->statName($name);

            if ($stat === false || $stat['size'] > self::MAX_PHP_BYTES) {
                continue;
            }

            $php = (string) $zip->getFromName($name);

            // $slots->register('order.confirmation', ...): a fixed slot or a single place.
            preg_match_all('/->register\(\s*[\'"]([a-z0-9_.]+)[\'"]/', $php, $registered);

            foreach ($registered[1] as $slot) {
                if (array_key_exists($slot, $slots) || array_key_exists($slot, $places)) {
                    $this->use("slot \"{$slot}\"", $slots[$slot] ?? $places[$slot]);
                } elseif (str_contains($slot, '.')) {
                    $this->error("{$name} registers the storefront slot \"{$slot}\", which Modrix Commerce does not have.");
                }
            }

            // $slots->block($module, $key, $label, $component, ['place', ...], 'default', ...): movable places.
            $offset = 0;

            while (($at = strpos($php, '->block(', $offset)) !== false) {
                $offset = $at + 8;
                $call = substr($php, $offset, 2000);

                if (preg_match('/\[([^\[\]]*)\]\s*,\s*[\'"]([a-z0-9_.]+)[\'"]/', $call, $m) !== 1) {
                    continue;
                }

                preg_match_all('/[\'"]([a-z0-9_.]+)[\'"]/', $m[1], $listed);

                foreach (array_unique([...$listed[1], $m[2]]) as $place) {
                    if (array_key_exists($place, $places)) {
                        $this->use("place \"{$place}\"", $places[$place]);
                    } else {
                        $this->error("{$name} lists \"{$place}\" as a block place, but that place cannot hold blocks. Stores would fail with \"lists a place that cannot hold blocks\".");
                    }
                }
            }

            // Names kept in constants or variables still count.
            foreach ([...$places, ...$slots] as $known => $version) {
                if (str_contains($php, "'{$known}'") || str_contains($php, "\"{$known}\"")) {
                    $this->use((array_key_exists($known, $places) ? 'place' : 'slot')." \"{$known}\"", $version);
                }
            }
        }
    }

    /**
     * Looks for malicious code. Files are read as text and PHP is tokenised,
     * never run. "Malicious" findings reject the package automatically;
     * "suspicious" ones are shown to the reviewer.
     */
    private function security(ZipArchive $zip, string $type): void
    {
        foreach (array_keys($this->names) as $name) {
            if (str_ends_with($name, '/') || str_starts_with($name, '__MACOSX/')) {
                continue;
            }

            if (preg_match(self::BLOCKED_FILES, $name) === 1) {
                $this->malicious("{$name}: packages may not contain server scripts, server configuration or executable files.");

                continue;
            }

            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if ($type === 'theme' && $extension === 'php') {
                $this->error("{$name}: themes cannot contain PHP files.");
            }

            $stat = $zip->statName($name);

            if ($stat === false) {
                continue;
            }

            $code = in_array($extension, ['php', 'js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx', 'svg', 'html', 'htm'], true);

            if ($stat['size'] > self::MAX_SCAN_BYTES) {
                if ($code) {
                    $this->suspicious("{$name} is larger than 2 MB and was not scanned. Check it by hand.");
                }

                continue;
            }

            $text = (string) $zip->getFromName($name);

            if (preg_match(self::SIGNATURES, $text, $m) === 1) {
                $this->malicious("{$name} contains \"{$m[0]}\", the name of a known web shell or browser crypto-miner.");
            }

            if ($extension === 'php') {
                $this->phpThreats($name, $text);
            } elseif (! in_array($extension, ['md', 'txt'], true) && preg_match($this->isBinary($extension) ? '/<\?php\s/i' : '/<\?(php\s|=)/i', $text) === 1) {
                // PHP hidden in an image, font, stylesheet or data file. Binary
                // data can hold the three bytes "<?=" by chance, so images and
                // fonts are checked for the full "<?php" tag only.
                $this->malicious("{$name} hides PHP code in a .{$extension} file.");
            }

            if (in_array($extension, ['js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx', 'html', 'htm'], true)) {
                $this->scriptThreats($name, $text);
            }

            if ($extension === 'svg' && preg_match('/<script|\bon[a-z]+\s*=|javascript:/i', $text) === 1) {
                $this->malicious("{$name} is an SVG image with scripts in it, which could run on the store's pages.");
            }

            if ($code && preg_match('/[\'"`][A-Za-z0-9+\/=]{2000,}[\'"`]/', $text) === 1) {
                $this->suspicious("{$name} contains a very long encoded string, which can hide code.");
            }
        }
    }

    private function isBinary(string $extension): bool
    {
        return in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'zip', 'pdf', 'mp4', 'webm', 'mp3'], true);
    }

    private function phpThreats(string $name, string $php): void
    {
        $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
        $tokens = array_values(array_filter(token_get_all($php), fn ($t) => ! is_array($t) || ! in_array($t[0], $skip, true)));
        $found = [];
        $flag = function (string $key, string $message, bool $malicious) use (&$found) {
            if (! isset($found[$key])) {
                $found[$key] = true;
                $malicious ? $this->malicious($message) : $this->suspicious($message);
            }
        };

        foreach ($tokens as $i => $token) {
            if ($token === '`') {
                $flag('`', "{$name} runs shell commands with the backtick operator.", true);

                continue;
            }

            if (! is_array($token)) {
                continue;
            }

            [$id, $text] = $token;

            if ($id === T_EVAL) {
                $flag('eval', "{$name} calls eval(), which runs code built at run time.", true);
            } elseif (in_array($id, [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) {
                $target = $tokens[$i + 1] ?? null;
                $target = $target === '(' ? ($tokens[$i + 2] ?? null) : $target;

                if (is_array($target) && $target[0] === T_CONSTANT_ENCAPSED_STRING && preg_match('#^[\'"]\s*(https?:|ftps?:|php://input|data:|expect:|phar:)#i', $target[1]) === 1) {
                    $flag('include', "{$name} loads PHP code from outside the package ({$target[1]}).", true);
                }
            } elseif (in_array($id, [T_STRING, T_NAME_FULLY_QUALIFIED], true) && ($tokens[$i + 1] ?? null) === '(') {
                $previous = $tokens[$i - 1] ?? null;

                // A method, static call, declaration or class name, not a call to the global function.
                if (is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST], true)) {
                    continue;
                }

                $function = strtolower(ltrim($text, '\\'));

                if (isset(self::DANGEROUS_FUNCTIONS[$function])) {
                    $flag($function, "{$name} calls {$function}(), which ".self::DANGEROUS_FUNCTIONS[$function].'.', true);
                } elseif (isset(self::RISKY_FUNCTIONS[$function])) {
                    $flag($function, "{$name} calls {$function}(), which ".self::RISKY_FUNCTIONS[$function].'.', false);
                }
            }
        }

        // $_GET['x']($_POST['y']): runs whatever function the request names.
        if (preg_match('/\$_(GET|POST|REQUEST|COOKIE|SERVER|FILES)\s*\[[^\]]*\]\s*\(/', $php) === 1) {
            $flag('callable-input', "{$name} calls a function named by the request, a typical backdoor.", true);
        }

        if (preg_match('/(base64_decode|str_rot13|gzinflate|gzuncompress)\s*\(\s*(base64_decode|str_rot13|gzinflate|gzuncompress|strrev)\s*\(/i', $php) === 1) {
            $flag('decode-chain', "{$name} unpacks data through several decoders, a common way to hide malicious code.", true);
        }

        if (preg_match('/(\\\\x[0-9a-fA-F]{2}){40,}|(chr\s*\(\s*\d+\s*\)\s*\.\s*){15,}/', $php) === 1) {
            $flag('obfuscated', "{$name} builds text from character codes, which can hide code.", false);
        }

        if (preg_match('/file_put_contents\s*\([^;]*\.php[\'"]/i', $php) === 1) {
            $flag('writes-php', "{$name} writes .php files, which can plant code on the store.", true);
        }
    }

    private function scriptThreats(string $name, string $script): void
    {
        if (preg_match('/\beval\s*\(\s*(atob|unescape|decodeURIComponent|String\.fromCharCode)\b/', $script) === 1) {
            $this->malicious("{$name} runs decoded hidden code with eval().");
        } elseif (preg_match('/(?<![\w.$])eval\s*\(|new\s+Function\s*\(|set(Timeout|Interval)\s*\(\s*[\'"`]/', $script) === 1) {
            $this->suspicious("{$name} runs code built from text (eval, new Function or a string timer).");
        }

        if (preg_match('/String\.fromCharCode\s*\(\s*(\d+\s*,\s*){20,}/', $script) === 1) {
            $this->suspicious("{$name} builds text from character codes, which can hide code.");
        }
    }

    /** Checks platform_version and returns it (null when missing or invalid). */
    private function platform(array $manifest, ?string $formPlatform): ?string
    {
        $constraint = is_string($manifest['platform_version'] ?? null) ? trim($manifest['platform_version']) : '';

        if ($constraint === '') {
            return null;
        }

        if (! $this->validConstraint($constraint)) {
            $this->error("platform_version \"{$constraint}\" is not a valid version constraint, such as ^1.0.");

            return null;
        }

        $formPlatform = trim((string) $formPlatform);

        if ($formPlatform !== '' && $formPlatform !== $constraint) {
            $this->error("The platform version you entered ({$formPlatform}) differs from the package's platform_version ({$constraint}). Stores check the package's, so make them match.");
        }

        $minimum = $this->minimumCore();
        $known = array_unique([...(array) config('compatibility.core_versions'), ...CoreRelease::query()->pluck('version')->all()]);
        $older = array_values(array_filter($known, fn (string $v) => $minimum !== null && $this->less($v, $minimum) && $this->satisfies($v, $constraint)));

        if ($older !== []) {
            usort($older, fn (string $a, string $b) => $this->less($a, $b) ? -1 : 1);
            $needed = $this->neededFor($minimum);
            $this->error("platform_version \"{$constraint}\" lets stores on core {$older[0]} install this package, but it uses {$needed}, which needs core {$minimum} or later. Set platform_version to \"^{$minimum}\".");
        }

        if (! array_filter($known, fn (string $v) => $this->satisfies($v, $constraint))) {
            $this->warning("No Modrix Commerce core release matches platform_version \"{$constraint}\" yet.");
        }

        return $constraint;
    }

    /**
     * @return array<string, string>
     */
    private function requires(mixed $requires): array
    {
        if ($requires === [] || $requires === null) {
            return [];
        }

        if (! is_array($requires) || array_is_list($requires)) {
            $this->error('"requires" must be an object of "vendor/package": "constraint".');

            return [];
        }

        $valid = [];

        foreach ($requires as $identifier => $constraint) {
            if (! is_string($identifier) || preg_match(self::NAME, $identifier) !== 1) {
                $this->error("requires: \"{$identifier}\" is not a valid package name.");

                continue;
            }

            if (! is_string($constraint) || ! $this->validConstraint($constraint)) {
                $this->error("requires: the version constraint for {$identifier} is not valid.");

                continue;
            }

            $valid[$identifier] = $constraint;
            $package = Package::query()->where('identifier', $identifier)->with('releases')->first();

            if ($package === null || $package->status !== 'published') {
                $this->warning("Requires {$identifier}, which is not in the marketplace. Stores must get it elsewhere before installing this package.");

                continue;
            }

            $versions = $package->releases->pluck('version')->push($package->version)->filter()->unique();

            if (! $versions->contains(fn (string $v) => $this->satisfies($v, $constraint))) {
                $this->warning("Requires {$identifier} {$constraint}, but the marketplace has only ".$versions->implode(', ').'.');
            }
        }

        return $valid;
    }

    private function report(?string $platform, array $requires): CompatibilityReport
    {
        $minimum = $this->minimumCore();
        $first = null;
        $latest = $this->cores->latest();

        if ($platform !== null) {
            $first = $this->cores->newerThan(null, null)->reverse()->first(
                fn (CoreRelease $r) => $this->satisfies($r->version, $platform) && ($minimum === null || ! $this->less($r->version, $minimum)),
            );

            if ($latest !== null && $first === null) {
                $this->warning('No published core release can run this package yet'.($minimum !== null ? " (it needs core {$minimum} or later)" : '').'. Stores can install it once a matching core is published.');
            }
        }

        ksort($this->uses);

        return new CompatibilityReport(
            issues: $this->issues,
            platformVersion: $platform,
            minimumCore: $minimum,
            firstCore: $first?->version,
            latestCore: $latest?->version,
            uses: array_filter($this->uses, fn (string $v) => $v === $minimum),
            requires: $requires,
            scannedAt: now()->toIso8601String(),
        );
    }

    private function minimumCore(): ?string
    {
        $minimum = null;

        foreach ($this->uses as $version) {
            if ($minimum === null || $this->less($minimum, $version)) {
                $minimum = $version;
            }
        }

        return $minimum;
    }

    /** "place "x" and slot "y"": the features that set the minimum core. */
    private function neededFor(string $minimum): string
    {
        $features = array_keys(array_filter($this->uses, fn (string $v) => $v === $minimum));

        return count($features) > 1 ? implode(', ', array_slice($features, 0, -1)).' and '.end($features) : (string) ($features[0] ?? 'its features');
    }

    private function use(string $feature, string $version): void
    {
        $this->uses[$feature] = $version;
    }

    private function hasFile(string $path): bool
    {
        return isset($this->names[$this->clean($path)]);
    }

    private function hasFolder(string $path): bool
    {
        $prefix = rtrim($this->clean($path), '/').'/';

        foreach (array_keys($this->names) as $name) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function clean(string $path): string
    {
        return ltrim(preg_replace('#^(\./)+#', '', str_replace('\\', '/', trim($path))), '/');
    }

    private function validConstraint(string $constraint): bool
    {
        try {
            (new VersionParser)->parseConstraints($constraint);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function satisfies(string $version, string $constraint): bool
    {
        try {
            return Semver::satisfies($version, $constraint);
        } catch (Throwable) {
            return false;
        }
    }

    private function less(string $a, string $b): bool
    {
        try {
            return Comparator::lessThan($a, $b);
        } catch (Throwable) {
            return false;
        }
    }

    private function error(string $message): void
    {
        $this->issues[] = ['level' => 'error', 'message' => $message];
    }

    private function warning(string $message): void
    {
        $this->issues[] = ['level' => 'warning', 'message' => $message];
    }

    private function malicious(string $message): void
    {
        $this->issues[] = ['level' => 'malicious', 'message' => $message];
    }

    private function suspicious(string $message): void
    {
        $this->issues[] = ['level' => 'suspicious', 'message' => $message];
    }
}
