<?php

return [
    'max_archive_bytes' => (int) env('VERIFICATION_MAX_ARCHIVE_BYTES', 1073741824),
    'chunk_bytes' => 524288,
    'max_pdf_files' => 1200,
    'max_pages' => 10000,
    'max_expanded_bytes' => 4294967296,
    'github_repository' => env('VERIFICATION_GITHUB_REPOSITORY', 'Riskcontrol/version2-ai-agent-booklet-extraction'),
];
