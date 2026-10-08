# opmin in the dload registry

[dload](https://github.com/php-internal/dload) downloads tool binaries pinned per project in `dload.xml`. To be
`vendor/bin/dload get opmin`, opmin needs an entry in `resources/software.json` of php-internal/dload. The pull
request is prepared here and sent after the first release with binaries (and after agreeing on it).

## The entry

```json
{
    "name": "opmin",
    "alias": "opmin",
    "binary": {
        "name": "opmin",
        "version-command": "--version"
    },
    "homepage": "https://github.com/melentev-av/opmin",
    "description": "Minimizes PHP opcodes without changing behavior: Rector and LLM rewrites verified by differential tests",
    "repositories": [
        {
            "type": "github",
            "uri": "melentev-av/opmin",
            "asset-pattern": "/^opmin-.*\\.tar\\.gz$/"
        }
    ]
}
```

The archives are named `opmin-<version>-<os>-<arch>.tar.gz` with `linux`/`macos` and `x86_64`/`aarch64`/`arm64`:
dload's `OperatingSystem` and `Architecture` recognize all of them; the pattern skips `opmin.phar`,
`sha256sum.txt` and its signature. dload checks no signature: `opmin self-update` and install.sh do.

## In a project

```xml
<?xml version="1.0"?>
<dload xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
       xsi:noNamespaceSchemaLocation="vendor/internal/dload/dload.xsd">
    <actions>
        <download software="opmin" version="^0.2" />
    </actions>
</dload>
```

dload puts the binary into the project root; a global `opmin` started in the project then hands the run over to
`./opmin`, the pinned version.
