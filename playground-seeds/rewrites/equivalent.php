<?php

/**
 * A correct rewrite of `Obvious.php`: [search, replace] applied together. `opmin verify --with-tests`
 * must accept it in every scenario that has the file.
 */
return [
    ["\$lower = strtolower(trim(\$title));\n        \$slug = preg_replace('/[^a-z0-9]+/', '-', \$lower);", "\$slug = \\preg_replace('/[^a-z0-9]+/', '-', \\strtolower(\\trim(\$title)));"],
    ["return trim((string) \$slug, '-');", "return \\trim((string) \$slug, '-');"],
    [
        "        if (is_array(\$value)) {\n            if (count(\$value) === 0) {\n                return 'empty array';\n            } else {\n                return 'array of ' . count(\$value);\n            }\n        } else {\n            if (is_string(\$value)) {\n                return 'string of ' . strlen(\$value);\n            } else {\n                return gettype(\$value);\n            }\n        }",
        "        if (\\is_array(\$value)) {\n            return \$value === [] ? 'empty array' : 'array of ' . \\count(\$value);\n        }\n\n        if (\\is_string(\$value)) {\n            return 'string of ' . \\strlen(\$value);\n        }\n\n        return \\gettype(\$value);",
    ],
    ["            \$price = \$prices[\$sku];\n\n            return \$price;", "            return \$prices[\$sku];"],
    ['for ($i = 0; $i < count($numbers); $i++) {', 'for ($i = 0, $n = \count($numbers); $i < $n; ++$i) {'],
];
