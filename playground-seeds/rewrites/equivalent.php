<?php

/**
 * Correct rewrites of seed files: file => [search, replace] pairs applied together. `opmin verify
 * --with-tests` must accept them in every scenario that has the file — `FileJournal.php` writes a file,
 * so only the project's tests (with a coverage map) can prove it; without one it stays unproven.
 */
return [
    'Obvious.php' => [
        ["\$lower = strtolower(trim(\$title));\n        \$slug = preg_replace('/[^a-z0-9]+/', '-', \$lower);", "\$slug = \\preg_replace('/[^a-z0-9]+/', '-', \\strtolower(\\trim(\$title)));"],
        ["return trim((string) \$slug, '-');", "return \\trim((string) \$slug, '-');"],
        [
            "        if (is_array(\$value)) {\n            if (count(\$value) === 0) {\n                return 'empty array';\n            } else {\n                return 'array of ' . count(\$value);\n            }\n        } else {\n            if (is_string(\$value)) {\n                return 'string of ' . strlen(\$value);\n            } else {\n                return gettype(\$value);\n            }\n        }",
            "        if (\\is_array(\$value)) {\n            return \$value === [] ? 'empty array' : 'array of ' . \\count(\$value);\n        }\n\n        if (\\is_string(\$value)) {\n            return 'string of ' . \\strlen(\$value);\n        }\n\n        return \\gettype(\$value);",
        ],
        ["            \$price = \$prices[\$sku];\n\n            return \$price;", "            return \$prices[\$sku];"],
        ['for ($i = 0; $i < count($numbers); $i++) {', 'for ($i = 0, $n = \count($numbers); $i < $n; ++$i) {'],
    ],
    'FileJournal.php' => [
        ["\$written = file_put_contents(\$this->path, \$line . PHP_EOL, FILE_APPEND);\n\n        return \$written === false ? 0 : \$written;", "return (int) \\file_put_contents(\$this->path, \$line . \\PHP_EOL, \\FILE_APPEND);"],
    ],
];
