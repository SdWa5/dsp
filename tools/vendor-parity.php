<?php

/**
 * Rebuild the evidence inventory or check its coverage without contacting a device.
 * Run with ddev exec php tools/vendor-parity.php --write, --check or --require-complete.
 * Completion means every outcome has a counterpart, an evidenced exclusion or a concrete unresolved TODO.
 * It does not mean every hardware question is resolved.
 */
$root = \dirname(__DIR__);
$args = array_slice($argv, 1);
$manifest = $root.'/docs/vendor-parity.json';
$index = array_search('--manifest', $args, true);
if (false !== $index) {
    $manifest = $args[$index + 1] ?? '';
}

try {
    $read = static function (string $file): array {
        $data = json_decode((string) file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($data)) {
            throw new RuntimeException('Expected an object in '.$file);
        }

        return $data;
    };
    $data = $read($manifest);
    $features = $data['features'];
    $todos = (string) file_get_contents($root.'/TODO.md');
    foreach (['pages' => 'page_tour.json', 'forms' => 'dialogs.json'] as $section => $source) {
        $expected = array_keys($read($root.'/captures/'.$source));
        $actual = array_keys($data[$section]);
        sort($expected);
        sort($actual);
        if ($expected !== $actual) {
            throw new RuntimeException('Coverage differs from captures/'.$source);
        }
    }
    $pending = [];
    $gaps = 0;
    $counts = array_fill_keys(['matched', 'unresolved', 'confirmed gap', 'internal/helper'], 0);
    foreach ($features as $id => $feature) {
        if (!\in_array($feature['status'], ['matched', 'unresolved', 'confirmed gap', 'internal/helper'], true)) {
            throw new RuntimeException('Invalid feature status for '.$id);
        }
        ++$counts[$feature['status']];
        if ('confirmed gap' === $feature['status']) {
            ++$gaps;
        }
        if ('matched' === $feature['status'] && [] === $feature['counterparts']) {
            throw new RuntimeException('Matched feature has no counterpart: '.$id);
        }
        if ('internal/helper' === $feature['status']) {
            $evidence = $feature['exclusion_evidence'] ?? '';
            if ('' === $feature['notes'] || '' === $evidence || !is_file($root.'/'.$evidence)) {
                throw new RuntimeException('Helper exclusion has no evidence: '.$id);
            }
        }
        if ('matched' !== $feature['status'] && 'internal/helper' !== $feature['status']) {
            if ('' === $feature['verify']) {
                throw new RuntimeException('Unresolved feature has no verification recipe: '.$id);
            }
            if (!str_contains($todos, 'VP-'.$id.'.')) {
                throw new RuntimeException('Unresolved feature has no TODO: '.$id);
            }
            $pending[] = $id;
        }
        foreach ($feature['counterparts'] as $counterpart) {
            $path = $root.'/'.$counterpart['file'];
            if (!is_file($path) || !str_contains((string) file_get_contents($path), $counterpart['contains'])) {
                throw new RuntimeException('Missing counterpart for '.$id.' in '.$counterpart['file']);
            }
        }
    }
    $missing = 0;
    foreach (['pages', 'forms', 'menus'] as $section) {
        foreach ($data[$section] as $id => $surface) {
            if ([] === $surface['features']) {
                throw new RuntimeException('No control mapping for '.$id);
            }
            foreach ($surface['features'] as $feature) {
                if (!isset($features[$feature])) {
                    throw new RuntimeException('Unknown feature '.$feature.' on '.$id);
                }
            }
            if ([] === $surface['screenshots']) {
                ++$missing;
                if ('' === ($surface['pending'] ?? '')) {
                    throw new RuntimeException('Missing capture has no follow-up: '.$id);
                }
            }
            if ('' !== ($surface['pending'] ?? '')) {
                $hasTodo = str_contains($todos, 'VP-capture-'.explode(' | ', $id)[0].'.');
                foreach ($surface['features'] as $feature) {
                    $hasTodo = $hasTodo || ('unresolved' === $features[$feature]['status'] || 'confirmed gap' === $features[$feature]['status']);
                }
                if (!$hasTodo) {
                    throw new RuntimeException('Capture follow-up has no unresolved TODO: '.$id);
                }
                $pending[] = $id;
            }
            foreach ($surface['screenshots'] as $shot) {
                $path = $root.'/'.$shot;
                if (!is_file($path) || "\x89PNG\r\n\x1A\n" !== file_get_contents($path, false, null, 0, 8)) {
                    throw new RuntimeException('Missing PNG evidence: '.$shot);
                }
            }
        }
    }
    if ([] !== $data['other_evidence']) {
        if (!str_contains($todos, 'VP-unassigned-evidence.')) {
            throw new RuntimeException('Unassigned evidence has no TODO.');
        }
        $pending[] = 'unassigned evidence';
    }
    $pending = array_unique($pending);
    if (\in_array('--require-complete', $args, true) && 0 !== $gaps) {
        echo \sprintf("The audit has %d confirmed gap(s) to implement.\n", $gaps);
        exit(2);
    }
    $cell = static fn (string $text): string => str_replace(["\n", '|'], [' ', '\\|'], $text);
    $link = static fn (string $file): string => '['.basename($file).'](../'.$file.')';
    $lines = [
        '# Vendor parity audit', '',
        'This audit started from trackdsp '.$data['baseline'].' on '.$data['date'].'. '.(0 === $gaps ? 'The inventory audit is complete under the agreed outcome rule. Full vendor parity is not certified.' : 'Confirmed implementation gaps keep the audit open.'), '',
        'The inventory maps '.\count($data['pages']).' tabs, '.\count($data['forms']).' named forms and '.\count($data['menus']).' top-level menu entries to '.\count($features).' control groups. '.$counts['matched'].' are matched, '.$counts['unresolved'].' unresolved, '.$counts['confirmed gap'].' confirmed gaps and '.$counts['internal/helper'].' evidenced exclusions. '.$missing.' surfaces have no screenshot; their concrete evidence TODOs remain recorded. '.\count($pending).' feature or evidence outcomes remain unresolved.', '',
        'Matched means a counterpart exists in the editor or CLI. It does not certify hardware behavior. Unknown forms remain unresolved; their names do not prove they are internal helpers.', '',
        'The screenshots of the original tour show a running preset, not factory defaults. The range survey in tools/shots/ranges uses first-channel default, second-channel maximum and third-channel minimum, with faders only. Offline screenshots establish UI bounds, not accepted device writes.', '',
        'The source is [vendor-parity.json](vendor-parity.json). Regenerate this document after recording a capture or changing a mapping.', '',
        '```bash', 'ddev exec php tools/vendor-parity.php --write', 'ddev exec php tools/vendor-parity.php --check', 'ddev exec php tools/vendor-parity.php --require-complete', '```', '',
        'The check validates coverage, source counterparts, PNG paths, unresolved TODOs, exclusion evidence and document freshness. The completeness check fails for confirmed implementation gaps or unrecorded outcomes. Explicit unknowns with concrete TODOs satisfy the agreed audit rule. Neither command connects to a bridge or device.', '',
        '## Control counterparts', '',
        '| ID | Vendor controls | Status | Trackdsp source | Findings and verification |', '|---|---|---|---|---|',
    ];
    foreach ($features as $id => $feature) {
        $sources = implode(', ', array_map(static fn (array $c): string => $link($c['file']), $feature['counterparts']));
        $lines[] = '| '.$cell($id).' | '.$cell($feature['controls']).' | '.$feature['status'].' | '.$sources.' | '.$cell(trim($feature['notes'].' '.$feature['verify'])).' |';
    }
    foreach (['pages' => 'Tabs', 'forms' => 'Forms', 'menus' => 'Menus'] as $section => $title) {
        array_push($lines,
            '', '## '.$title, '',
            '| Vendor surface | Entry or state | Screenshot evidence | Control groups | Capture follow-up |',
            '|---|---|---|---|---|'
        );
        foreach ($data[$section] as $id => $surface) {
            $state = ($surface['entry'] ?? '').' '.$surface['capture'];
            if (isset($surface['entries']) && [] !== $surface['entries']) {
                $state .= '. Entries: '.implode(', ', $surface['entries']);
            }
            $shots = implode(', ', array_map($link, $surface['screenshots']));
            $lines[] = '| '.$cell($id).' | '.$cell(trim($state)).' | '.$shots.' | '.implode(', ', $surface['features']).' | '.$cell($surface['pending'] ?? '').' |';
        }
    }
    array_push($lines, '', '## Unassigned evidence', '');
    foreach ($data['other_evidence'] as $file => $note) {
        $lines[] = '- '.$link($file).'. '.$note;
    }
    $document = implode("\n", $lines)."\n";
    $output = $root.'/docs/vendor-parity.md';
    if (\in_array('--write', $args, true)) {
        file_put_contents($output, $document);
    } elseif (!is_file($output) || file_get_contents($output) !== $document) {
        throw new RuntimeException('Regenerate docs/vendor-parity.md with --write.');
    }
    echo \sprintf("Coverage is recorded for %d tabs and %d forms. %d surfaces lack screenshots and %d evidence outcomes remain open.\n", \count($data['pages']), \count($data['forms']), $missing, \count($pending));

} catch (Throwable $error) {
    fwrite(\STDERR, $error->getMessage()."\n");
    exit(1);
}
