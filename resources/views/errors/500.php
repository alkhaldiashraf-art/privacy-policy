<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Something went wrong · SIUGOALS</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="guest-shell">
    <div class="guest-card" style="text-align:center;">
        <h1>Something went wrong</h1>
        <p class="text-muted">We've logged the problem. Reference: <?= htmlspecialchars($errorId ?? '', ENT_QUOTES) ?></p>
        <a class="btn btn-primary" href="/">Go home</a>
    </div>
</div>
</body>
</html>
