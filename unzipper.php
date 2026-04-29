<?php
if (isset($_POST['unzip'])) {
    $zipFile = $_POST['zip_file'];

    if (!file_exists($zipFile)) {
        $message = "ZIP file not found!";
    } else {
        $zip = new ZipArchive;

        if ($zip->open($zipFile) === TRUE) {
            $extractPath = dirname($zipFile);
            $zip->extractTo($extractPath);
            $zip->close();
            $message = "File extracted successfully in: " . $extractPath;
        } else {
            $message = "Failed to open ZIP file!";
        }
    }
}

// Get all zip files in current directory
$zipFiles = glob("*.zip");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Unzipper Tool</title>
    <style>
        body { font-family: Arial; padding: 20px; background:#f4f6f9; }
        .box { background:#fff; padding:20px; border-radius:8px; max-width:500px; margin:auto; }
        select, button { padding:10px; width:100%; margin-top:10px; }
        .msg { margin-top:15px; font-weight:bold; }
    </style>
</head>
<body>

<div class="box">
    <h2>Unzip File</h2>

    <form method="post">
        <label>Select ZIP file:</label>
        <select name="zip_file" required>
            <option value="">-- Select ZIP --</option>
            <?php foreach ($zipFiles as $file): ?>
                <option value="<?= $file ?>"><?= $file ?></option>
            <?php endforeach; ?>
        </select>

        <button type="submit" name="unzip">Unzip Here</button>
    </form>

    <?php if (!empty($message)): ?>
        <div class="msg"><?= $message ?></div>
    <?php endif; ?>
</div>

</body>
</html>