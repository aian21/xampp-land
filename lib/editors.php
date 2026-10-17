<?php
// Finds code editors installed on this computer and opens project folders in them.

/** Known editors, in the order they're offered. Paths are globs; {L} is %LOCALAPPDATA%\Programs, {P} and {X} are Program Files. */
const EDITORS = [
    'vscode'   => ['VS Code', ['{L}\Microsoft VS Code\Code.exe', '{P}\Microsoft VS Code\Code.exe']],
    'cursor'   => ['Cursor', ['{L}\cursor\Cursor.exe', '{P}\Cursor\Cursor.exe']],
    'windsurf' => ['Windsurf', ['{L}\Windsurf\Windsurf.exe']],
    'antigravity' => ['Antigravity', ['{L}\Antigravity*\Antigravity*.exe']],
    'kiro'     => ['Kiro', ['{L}\Kiro\Kiro.exe']],
    'trae'     => ['Trae', ['{L}\Trae\Trae.exe']],
    'vscodium' => ['VSCodium', ['{L}\VSCodium\VSCodium.exe', '{P}\VSCodium\VSCodium.exe']],
    'insiders' => ['VS Code Insiders', ['{L}\Microsoft VS Code Insiders\Code - Insiders.exe', '{P}\Microsoft VS Code Insiders\Code - Insiders.exe']],
    'phpstorm' => ['PhpStorm', ['{L}\PhpStorm*\bin\phpstorm64.exe', '{P}\JetBrains\PhpStorm*\bin\phpstorm64.exe']],
    'webstorm' => ['WebStorm', ['{L}\WebStorm*\bin\webstorm64.exe', '{P}\JetBrains\WebStorm*\bin\webstorm64.exe']],
    'idea'     => ['IntelliJ IDEA', ['{L}\IntelliJ IDEA*\bin\idea64.exe', '{P}\JetBrains\IntelliJ IDEA*\bin\idea64.exe']],
    'zed'      => ['Zed', ['{L}\Zed\Zed.exe']],
    'sublime'  => ['Sublime Text', ['{P}\Sublime Text*\sublime_text.exe']],
    'notepadpp' => ['Notepad++', ['{P}\Notepad++\notepad++.exe', '{X}\Notepad++\notepad++.exe'], '-openFoldersAsWorkspace'],
];

/** Returns [id => ['name' => ..., 'exe' => ..., 'args' => ...]] for every editor found. */
function detect_editors(): array
{
    if (!IS_WINDOWS) {
        return [];
    }
    $local = getenv('LOCALAPPDATA') ?: (getenv('USERPROFILE') ? getenv('USERPROFILE') . '\AppData\Local' : null);
    $roots = [
        '{L}' => $local ? $local . '\Programs' : null,
        '{P}' => getenv('ProgramFiles') ?: 'C:\Program Files',
        '{X}' => getenv('ProgramFiles(x86)') ?: 'C:\Program Files (x86)',
    ];

    $found = [];
    foreach (EDITORS as $id => $def) {
        foreach ($def[1] as $pattern) {
            $root = substr($pattern, 0, 3);
            if ($roots[$root] === null) continue;
            $matches = glob($roots[$root] . substr($pattern, 3)) ?: [];
            // Skip uninstallers matched by wildcards, and prefer the newest version folder.
            $matches = array_filter($matches, fn($f) => !preg_match('/unins\d*\.exe$/i', $f));
            natsort($matches);
            if ($matches) {
                $found[$id] = ['name' => $def[0], 'exe' => end($matches), 'args' => $def[2] ?? ''];
                break;
            }
        }
    }
    return $found;
}

function open_in_editor(string $editor, string $project): string
{
    $editors = detect_editors();
    if (!isset($editors[$editor])) {
        throw new RuntimeException('That editor was not found on this computer.');
    }
    // Only folders directly in htdocs. % is refused because cmd expands it even inside quotes.
    $name = basename($project);
    $path = HTDOCS . DIRECTORY_SEPARATOR . $name;
    if ($name === '' || $name !== $project || $name[0] === '.' || !is_dir($path)) {
        throw new RuntimeException('Project not found');
    }
    if (strpbrk($path, '%"') !== false) {
        throw new RuntimeException('Folder names with % can\'t be opened from here.');
    }
    $e = $editors[$editor];
    // Output goes to NUL so the request doesn't wait on the editor. ELECTRON_RUN_AS_NODE is cleared
    // because Apache started from a VS Code terminal inherits it, and Electron editors then ignore the folder.
    pclose(popen('set "ELECTRON_RUN_AS_NODE=" & start "" "' . $e['exe'] . '" ' . ($e['args'] ? $e['args'] . ' ' : '') . '"' . $path . '" >NUL 2>&1', 'r'));
    return 'Opening ' . $name . ' in ' . $e['name'];
}
