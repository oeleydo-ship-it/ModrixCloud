@php($limit = \Marketplace\Services\Uploads::serverLimitLabel())
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>File too large</title>
    <link rel="stylesheet" href="{{ asset('site.css') }}">
</head>
<body class="marketplace-body">
    <main class="container">
        <section class="panel form-panel">
            <span class="eyebrow">UPLOAD</span>
            <h1>The file is too large for this server</h1>
            <p>PHP on this server accepts uploads up to <strong>{{ $limit ?? 'its configured limit' }}</strong>, so the upload was refused before Modrix Cloud could read it.</p>
            <p class="meta">Raise <code>upload_max_filesize</code> and <code>post_max_size</code> in your hosting panel's PHP settings (for example 256M and 260M), then try again. On servers that read <code>public/.user.ini</code>, the bundled one sets these; changes there apply within five minutes.</p>
            <p><a class="button secondary" href="javascript:history.back()">Go back</a></p>
        </section>
    </main>
</body>
</html>
