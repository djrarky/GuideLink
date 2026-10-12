<?php

return array_map(fn (array $row): array => [
    'number' => $row[0],
    'name' => $row[1],
    'category' => $row[2],
    'aliases' => $row[3] ?? [],
    'stream_id' => '',
    'guide_valid' => true,
], [
    [169, 'Nature Test', 'Entertainment'],
    [199, 'Local Test +1', 'Entertainment'],
    [330, 'Movie Test', 'Movies', ['Old Movie']],
    [331, 'Movie Test +1', 'Movies', ['Old Movie']],
    [987, 'Local Test +1 West', 'Regional'],
    [996, 'Access Test', 'Entertainment'],
]);
