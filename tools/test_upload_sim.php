<?php
$base = __DIR__ . '/../assets/handouts';
$course = 'test_course';
$src = __DIR__ . '/../assets/handouts/test_write.txt';
if (!is_dir($base)) {
    if (!mkdir($base, 0755, true)) {
        echo "Failed to create base dir: $base\n";
        exit(1);
    }
}
$courseDir = $base . '/' . $course;
if (!is_dir($courseDir)) {
    mkdir($courseDir, 0755, true);
}
if (!file_exists($src)) {
    file_put_contents($src, "Generated test file at " . date('c') . "\n");
}
$safeName = preg_replace('/[^a-z0-9_\-\.]/i', '_', basename($src));
$targetName = time() . '_' . $safeName;
$targetPath = $courseDir . '/' . $targetName;
if (!copy($src, $targetPath)) {
    echo "Failed to copy $src to $targetPath\n";
    exit(1);
}
$meta = [
    'original' => basename($src),
    'uploaded_at' => date('c'),
    'type' => 'handout',
    'description' => 'Simulated test upload',
];
file_put_contents($targetPath . '.meta.json', json_encode($meta, JSON_PRETTY_PRINT));

echo "Simulated upload successful:\n";
echo "- Dest: $targetPath\n";
echo "- Meta: " . $targetPath . '.meta.json' . "\n";

$files = glob($courseDir . '/*');
foreach ($files as $f) {
    echo "  - " . basename($f) . " (" . filesize($f) . " bytes)\n";
}
