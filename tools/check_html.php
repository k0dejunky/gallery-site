<?php
/**
 * A tag-stack checker for the views: it reports a closing tag that does not
 * match the element it closes (and the ones left open at the end).
 *
 *   php tools/check_html.php [file ...]
 *
 * It is the "the admin dashboard is jumbled" class of bug: a <div> closed by
 * </p> makes everything after it a child of that div, and the layout turns
 * into columns.
 */
declare(strict_types=1);

$files = array_slice($argv, 1);
if ($files === []) {
    $files = glob(__DIR__ . '/../views/admin/*.php') ?: [];
    $files = array_merge($files, glob(__DIR__ . '/../views/*.php') ?: []);
}

$void = ['area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr'];

$problems = 0;

foreach ($files as $file) {
    $src = (string) file_get_contents($file);
    // Drop the PHP blocks (they can print markup, but we cannot parse it).
    $src = preg_replace('/<\?.*?\?>/s', '', $src) ?? $src;
    $src = preg_replace('/<\?php.*?:\s*\?>/s', '', $src) ?? $src;

    $stack = [];
    $line  = 1;
    $len   = strlen($src);

    for ($i = 0; $i < $len; $i++) {
        $ch = $src[$i];
        if ($ch === "\n") {
            $line++;
            continue;
        }
        if ($ch !== '<') {
            continue;
        }
        $end = strpos($src, '>', $i);
        if ($end === false) {
            break;
        }
        $tag = trim(substr($src, $i + 1, $end - $i - 1));
        $i   = $end;

        if ($tag === '' || $tag[0] === '/' || $tag[0] === '!' || $tag[0] === '?') {
            continue;
        }
        $closing = $tag[0] === '/';
        $name    = strtolower(preg_replace('/[^a-z0-9].*/i', '', $closing ? substr($tag, 1) : $tag));
        if ($name === '') {
            continue;
        }
        $selfClosing = str_ends_with(rtrim($tag), '/') || in_array($name, $void, true);

        if (!$closing) {
            if (!$selfClosing) {
                $stack[] = ['name' => $name, 'line' => $line];
            }
            continue;
        }

        // A closing tag: it must match the top of the stack.
        $top = end($stack);
        if ($top === false) {
            printf("%s:%d  </%s> closes nothing\n", $file, $line, $name);
            $problems++;
            continue;
        }
        if ($top['name'] === $name) {
            array_pop($stack);
            continue;
        }
        // Mismatch: is the named element somewhere deeper in the stack?
        $targetIdx = null;
        foreach ($stack as $k => $item) {
            if ($item['name'] === $name) {
                $targetIdx = $k;
            }
        }
        if ($targetIdx !== null) {
            // Everything from the named element up is closed by this tag: the
            // elements in between are left open (that is the bug).
            for ($k = count($stack) - 1; $k >= $targetIdx; $k--) {
                $item = $stack[$k];
                printf("%s:%d  </%s> closes the <%s> opened on line %d\n",
                    $file, $line, $name, $item['name'], $item['line']);
                $problems++;
            }
            $stack = array_slice($stack, 0, $targetIdx);
        } else {
            printf("%s:%d  </%s> does not match the open <%s> (line %d)\n",
                $file, $line, $name, $top['name'], $top['line']);
            $problems++;
            // The nesting is broken from here on, so the rest of the file is
            // checked with an empty stack (a new report means a new problem).
            $stack = [];
        }
    }

    foreach ($stack as $item) {
        printf("%s: <%s> opened on line %d is never closed\n", $file, $item['name'], $item['line']);
        $problems++;
    }
}

printf("%d problem(s) in %d file(s)\n", $problems, count($files));
exit($problems > 0 ? 1 : 0);
