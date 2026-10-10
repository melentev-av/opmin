# Changelog

## [0.3.0](https://github.com/melentev-av/opmin/compare/v0.2.0...v0.3.0) (2026-10-10)


### Features

* **config:** cache.driver memory keeps counts and references for one run ([#17](https://github.com/melentev-av/opmin/issues/17)) ([4c08fd5](https://github.com/melentev-av/opmin/commit/4c08fd55ab715062d538298a8c721faed1c99068))
* **config:** cache.driver sqlite, used automatically when pdo_sqlite is loaded ([d0d0815](https://github.com/melentev-av/opmin/commit/d0d08154b3a54df060964105142857215fa53e80)), closes [#9](https://github.com/melentev-av/opmin/issues/9)
* **config:** cache.recreate_corrupt deletes a corrupt SQLite cache and starts anew ([421a2f5](https://github.com/melentev-av/opmin/commit/421a2f5bd8f109603241a385ad9b95cc859807b7))
* **config:** the commands that analyze a project need opmin.yaml ([fc34ebc](https://github.com/melentev-av/opmin/commit/fc34ebcf90213afc799c6d3bd1d19324147df05d))
* **optimize:** stop when the project's tests fail on the original code ([add2ac9](https://github.com/melentev-av/opmin/commit/add2ac9001f93801ba4385c7fd7beb33aa5b986a))
* **optimize:** the git clean check is off by default and covers only the run's files ([#15](https://github.com/melentev-av/opmin/issues/15)) ([70f2daf](https://github.com/melentev-av/opmin/commit/70f2daf657a2c5898c4597029cfb76d728f78c64)), closes [#12](https://github.com/melentev-av/opmin/issues/12)
* **report:** explain every section, column and reason of report.md ([7bc7a5b](https://github.com/melentev-av/opmin/commit/7bc7a5bb3234b69f2d1c6e369c2b94db45e5586a))


### Bug Fixes

* **analysis:** index files with names that are not utf-8 ([cd06802](https://github.com/melentev-av/opmin/commit/cd0680297782e8a5663547418ce38da8e48f714c))
* **analysis:** keep hidden directories out of the reference index ([#19](https://github.com/melentev-av/opmin/issues/19)) ([9bf9746](https://github.com/melentev-av/opmin/commit/9bf97463c64c5127fc6d9ff3efe0e53f2d726fc4)), closes [#18](https://github.com/melentev-av/opmin/issues/18)
* **config:** resolve a relative cache.dir next to the config in use ([#16](https://github.com/melentev-av/opmin/issues/16)) ([b7f3f81](https://github.com/melentev-av/opmin/commit/b7f3f81bb55d7146d7fbfa1fe2b21a9ea6fa5bc3))
* **count:** an arrow function ends on the line of the next token ([f6bea2b](https://github.com/melentev-av/opmin/commit/f6bea2b14546f207b59600882c7c9e80b43a31a5))
* **count:** anonymous classes the compiler drops have no block in the dump ([#23](https://github.com/melentev-av/opmin/issues/23)) ([c8b7925](https://github.com/melentev-av/opmin/commit/c8b7925aabb2e93ebe44820dacbc3e9c65c417e8))
* **count:** closures of frameless calls are dumped twice ([8a100be](https://github.com/melentev-av/opmin/commit/8a100be0341dbb0ef07b9221ed426c17cc7ec972))
* **count:** closures the compiler drops have no block in the dump ([591342e](https://github.com/melentev-av/opmin/commit/591342e45a28a1a0f4a74fdda59d34f09995dfc7))
* **docker:** intl and bcmath in the image ([a7ab7a5](https://github.com/melentev-av/opmin/commit/a7ab7a529f0b13c231dee86c98575b7d20a28dc5))
* **harness:** no deadlock between a long request and output of the worker ([bdd5321](https://github.com/melentev-av/opmin/commit/bdd53217e68d9410d874c072492f119b2674e816))
* **harness:** read the tail of stderr before reporting a crash ([#22](https://github.com/melentev-av/opmin/issues/22)) ([447b440](https://github.com/melentev-av/opmin/commit/447b440b42d624efbbf2728f32d461ad9ccbcb91))
* **harness:** talk to the worker over sockets on Windows ([#11](https://github.com/melentev-av/opmin/issues/11)) ([f4afaec](https://github.com/melentev-av/opmin/commit/f4afaecc14f91f739a5069778ed0a01402fde1a7)), closes [#10](https://github.com/melentev-av/opmin/issues/10)
* **init:** take the code paths from the autoload of composer.json ([708a116](https://github.com/melentev-av/opmin/commit/708a116390874c8e4c8e7a746a4f60b9106a2cc4))
* **package:** keep the download cache of composer between runs ([346a75e](https://github.com/melentev-av/opmin/commit/346a75e2215d4e975fae836884863bce1adf0192))
* **package:** ship harness/ in the composer dist archive ([#13](https://github.com/melentev-av/opmin/issues/13)) ([ecf5975](https://github.com/melentev-av/opmin/commit/ecf59751a2bb2eac84c9121244fbb1efef90c367))
* **package:** show why composer install of a package failed ([56e6432](https://github.com/melentev-av/opmin/commit/56e643291eacf11b7847ef5628b5c1a779ed2604))
* **project:** keep the config's directory as the root of a monorepo package ([#14](https://github.com/melentev-av/opmin/issues/14)) ([4e04ea9](https://github.com/melentev-av/opmin/commit/4e04ea9ab877d9fa9a9680b66fce295882e92d17))
* **project:** skip test files next to the code by default ([38ebac1](https://github.com/melentev-av/opmin/commit/38ebac1f2f0ad91dea3964bde19948322e5a4e89))
* **rector:** let a call of a known pure built-in function count as pure ([#21](https://github.com/melentev-av/opmin/issues/21)) ([d2737a1](https://github.com/melentev-av/opmin/commit/d2737a18e115d0485e41cf4c1134443e235549ec)), closes [#20](https://github.com/melentev-av/opmin/issues/20)
* **self-update:** end the process right after the file is replaced ([06e0ebc](https://github.com/melentev-av/opmin/commit/06e0ebc927f6074a50ddd069e39a23e7ec9d314e))
* **skill:** opcode-minimize — unrendered version, run branch, patterns in PATTERNS.md ([#7](https://github.com/melentev-av/opmin/issues/7)) ([9d7e5e8](https://github.com/melentev-av/opmin/commit/9d7e5e8cb6b658b63e926dd0f431a94dbbc19df5))
* **tests:** a filter of thousands of tests fits into the command line ([c7cf4b0](https://github.com/melentev-av/opmin/commit/c7cf4b09160275091137af036b98bf139dd9b2d6))
* **tests:** read junit and coverage reports without ext-dom ([ea0eea5](https://github.com/melentev-av/opmin/commit/ea0eea5496b453959d261a28861cfda5d9c8ee80))


### Documentation

* **readme:** results on real packages, their tests without network ([827cf27](https://github.com/melentev-av/opmin/commit/827cf270b5f5dff7fb6822dd59c542d3e2f61289))
* **readme:** smoke results from CI with php.binary 8.1 and 8.5 ([dfb4f6e](https://github.com/melentev-av/opmin/commit/dfb4f6e2050af51bc7ad88a6b587fd126ce33d02))

## [0.2.0](https://github.com/melentev-av/opmin/compare/v0.1.0...v0.2.0) (2026-10-08)


### Features

* **analysis:** flag dynamic constructs per function and write the flags to count ([81aa32e](https://github.com/melentev-av/opmin/commit/81aa32e4bdd3816000fa6dbdf7e7e00707eb59b8))
* **analysis:** index the functions and constants that shadow global ones ([f72a5a0](https://github.com/melentev-av/opmin/commit/f72a5a0aaa25548ac5858fa2c2821c9b2053f8f0))
* **baseline:** write opmin.baseline.json with the opcode counts ([2455071](https://github.com/melentev-av/opmin/commit/2455071763dc98d27466e8f12d6734cc5cebd1aa))
* **check:** fail ci when opcodes grow compared to the baseline ([f5d5b43](https://github.com/melentev-av/opmin/commit/f5d5b438c4e654c35da91c9988bc7d58c83db374))
* **count:** count opcodes per function under php.binary, compare reports with diff ([cf32dbb](https://github.com/melentev-av/opmin/commit/cf32dbb7cb395578ede6c87e1aa7c262d9cb80f4))
* **docker:** image with the static binary and the php of production ([2756494](https://github.com/melentev-av/opmin/commit/275649406d7d97fa45aa172d035226acc37aac90))
* **doctor:** check php.binary, opcache, the harness, tests and phpstan ([3e66b00](https://github.com/melentev-av/opmin/commit/3e66b00a8dbfcc4ed4f182ede22e1e9ab1c9a5b7))
* **harness:** add the PHP 8.1 worker that calls one version of a function ([7ce4e06](https://github.com/melentev-av/opmin/commit/7ce4e06cc3e4ac1dbedd3276f2965a0b5eaad5b5))
* **ignore:** add the Opmin\Ignore attribute class and plain comment marks ([d51589b](https://github.com/melentev-av/opmin/commit/d51589b905f8d818fa0874672a8e4a45127baea1))
* **init:** detect php target, paths, test runner, formatter and phpstan ([0efb58e](https://github.com/melentev-av/opmin/commit/0efb58edc33241e8c7c3314ccebc4c3e63e9cd4d))
* **llm:** add apply-candidate and the LLM session commands ([3ed9f56](https://github.com/melentev-av/opmin/commit/3ed9f560461a29ff3c4c098e66af2557881e50f2))
* opcode counting, verification, optimization, ci guard and delivery (m1–m7) ([543506a](https://github.com/melentev-av/opmin/commit/543506a1b2c6e4a0d9f75d54747536816eb4b958))
* **optimize:** add --review with declined changes in opmin.baseline.yaml ([2ac89f7](https://github.com/melentev-av/opmin/commit/2ac89f7ac2ae212f8c2e524dfaf967ccd420ecaf))
* **optimize:** allow a dirty git tree with --dry-run ([abcfb1e](https://github.com/melentev-av/opmin/commit/abcfb1eb6daef445f09a819a5b51bbc959b8f344))
* **optimize:** apply Rector rules one by one with counting and verification ([9267772](https://github.com/melentev-av/opmin/commit/9267772c01ae9b820942160c6e53a7188b6c3cb8))
* **optimize:** resume interrupted runs and stop cleanly on signals ([c7d81ad](https://github.com/melentev-av/opmin/commit/c7d81ad225c6cf5935f046d8cae75f76b22325b8))
* **package:** optimize a git package by url in docker ([08ba2b1](https://github.com/melentev-av/opmin/commit/08ba2b10c173b862b29f79c6a85d3fc1fd966de6))
* **phar:** composer package of the phar and a test in a project with other rector ([8c51eb7](https://github.com/melentev-av/opmin/commit/8c51eb777f009082fa1801e956cbba14af6ad7bf))
* **rector:** add ExtractRepeatedArrayDimFetchRector and HoistLoopInvariantCountRector ([fdbe92e](https://github.com/melentev-av/opmin/commit/fdbe92e39f230e5912bd44d8e347c73e7fbbab04))
* **rector:** add ExtractRepeatedPropertyFetchRector ([98aa2d6](https://github.com/melentev-av/opmin/commit/98aa2d67d4b35981254a19881659793fc0bade11))
* **rector:** add FullyQualifyGlobalCallsRector ([0c3cb09](https://github.com/melentev-av/opmin/commit/0c3cb0997c220eed842a87d9c26f415ec74eadfd))
* **release:** pin the opmin version per project and hand over to a local opmin ([db8e6af](https://github.com/melentev-av/opmin/commit/db8e6af99ab650037e2f3acafcb6a573467267c4))
* **report:** write report.md and a versioned report.json for every run ([919a120](https://github.com/melentev-av/opmin/commit/919a1206bc2b9d9573c2d50ce2006c92c7bb04e9))
* **self-update:** signed releases, self-update and install.sh ([def0f09](https://github.com/melentev-av/opmin/commit/def0f09cc856eae4eedcacf952a19e658e6424e0))
* **skill:** ship the Claude Code skill opcode-minimize ([1e52b7d](https://github.com/melentev-av/opmin/commit/1e52b7d463e71d4ac05bfa6bc9304c8c9170c1d7))
* **tests:** add test runner adapters, the static check and counterexample tests ([4f36568](https://github.com/melentev-av/opmin/commit/4f365689a99ec7ce6073db54e91eff7727a6dc9e))
* **tests:** check coverage maps live, make Pest filter by its descriptions ([cf940dd](https://github.com/melentev-av/opmin/commit/cf940dd5f2abec00f3c569492b8ad6521c1fc922))
* **verification:** compare call results and keep harness workers alive ([6842f1b](https://github.com/melentev-av/opmin/commit/6842f1b21a159df0fbd48efbcc65c1c6b528e940))
* **verification:** drive property-testing-core through a thin runner adapter ([375f12e](https://github.com/melentev-av/opmin/commit/375f12e4c535161c27a0cdbbf52794ada387814c))
* **verification:** generate inputs, measure coverage and diff-test a function ([cf986cd](https://github.com/melentev-av/opmin/commit/cf986cdb6d99b1a2536359747fa523457e3f274e))
* **verification:** leave branches PHPStan proves dead out of the coverage ([1aec0be](https://github.com/melentev-av/opmin/commit/1aec0be0bc7300258b313dca1cc8ead2e181a7d3))
* **verifier:** roll back slower changes with --guard-perf ([66af8ce](https://github.com/melentev-av/opmin/commit/66af8cec15cb7f807472931fd387eb704ea74c46))
* **verify:** verify a changed file on all three levels ([632643d](https://github.com/melentev-av/opmin/commit/632643db280255db166a0b6ab8345118cb101a2e))


### Bug Fixes

* **count:** sort php versions with a typed callback, psalm on php 8.3 rejected version_compare ([adc6f31](https://github.com/melentev-av/opmin/commit/adc6f318e91d6f64c1e89250c45b842c581199fd))
* **harness:** extract the harness from the phar and the static binary ([a21a40a](https://github.com/melentev-av/opmin/commit/a21a40acb069ab6d48bf02332ce4047f7fa71341))
* **harness:** keep fuzzed code from writing into the project ([f285ec0](https://github.com/melentev-av/opmin/commit/f285ec08fefff76623c19faf97d3a5649127d407))
* **infection:** create the report directories before the first write ([bdbce89](https://github.com/melentev-av/opmin/commit/bdbce896748bb21ffa222fcd0edf59cea2979107))
* **optimize:** describe a step of an executed-gain rule without a static gain ([fd9adba](https://github.com/melentev-av/opmin/commit/fd9adba7cf044e18fe103ce7e4dc1ccd771e0312))
* **optimize:** give every Rector rule a cache of its own ([d975542](https://github.com/melentev-av/opmin/commit/d975542e9308680c90d03bd5056ae074643c8c2f))
* **optimize:** replace files through a rename and report why a file is not counted ([1816ee1](https://github.com/melentev-av/opmin/commit/1816ee1ad0f7c063dacc169e1d3344f0a5212298))
* **phar:** run rector and phpstan from the phar and the static binary ([0054cab](https://github.com/melentev-av/opmin/commit/0054cab232cc0ebc73df4edc604db3140caf833f))
* **playground:** wait for Docker Desktop to see files rewritten just now ([f1400b8](https://github.com/melentev-av/opmin/commit/f1400b832f59c39a7f7edc9f0e3bd9c3dc1c7e10))
* **rector:** let arithmetic between repeated reads count as pure ([6ca4d12](https://github.com/melentev-av/opmin/commit/6ca4d12dab720f92f98ebc72dbe4b49b1510ed57))
* **release:** cache key of static-php-cli without commas ([eee39f3](https://github.com/melentev-av/opmin/commit/eee39f3dd478ca88c96db4dd755d189e9b73fe19))
* **release:** no external php in the smoke tests of the macos intel binary ([724bb8d](https://github.com/melentev-av/opmin/commit/724bb8d47255fe50d3ea0a5a3204a0f0c6d5facf))
* **release:** php 8.4 as php.binary of the macos smoke tests ([5c1d04b](https://github.com/melentev-av/opmin/commit/5c1d04b2af7e8ccd9c624216757687a0d77868cf))
* **report:** show no coverage for functions the differential test did not run ([55cdc4c](https://github.com/melentev-av/opmin/commit/55cdc4c03cb88c4059f55f018e6a312b548ef733))
* **tests:** run PHPStan as a bare PHAR in the dead-branch test ([d93cb41](https://github.com/melentev-av/opmin/commit/d93cb412386c9a0727b843307e9808e0e194a54e))
* **verification:** repair bugs that mutation testing brought out ([444055c](https://github.com/melentev-av/opmin/commit/444055cd213fe8a918ab8436b97c3eba55c2165e))
* **verify:** accept absolute paths outside the current directory ([968f618](https://github.com/melentev-av/opmin/commit/968f618f8d37910b2dc23541a7f441992baaa4bf))


### Documentation

* **readme:** ci guard with github actions and gitlab ci examples ([f2de34a](https://github.com/melentev-av/opmin/commit/f2de34ab796f9a45a36804114fc5362316611b16))
* **readme:** describe the Rector stage of optimize ([c73c987](https://github.com/melentev-av/opmin/commit/c73c98793daa7b401c797e997f8287a8c72fd8dc))
* **readme:** installation, quick start, git packages, verifying a release ([ace945a](https://github.com/melentev-av/opmin/commit/ace945acedab4d60c0f112889c292bb621bb8570))
* **rector:** measure the standard Rector rules and keep only those that save opcodes ([52f7622](https://github.com/melentev-av/opmin/commit/52f7622119e90a344fc9b3f7ab9c96ba8563cded))
* **testing:** describe the tests of the rules and of the optimize pipeline ([a67a1ea](https://github.com/melentev-av/opmin/commit/a67a1ea62869a0c21715c608ac672fc9e3e3800a))


### Code Refactoring

* **analysis:** recognize ignore marks on functions and classes in one place ([ed8776e](https://github.com/melentev-av/opmin/commit/ed8776edaa9123c5ded7bd12f7152d2ee5abe202))
* **project:** resolve target paths and globs for every command ([8f9d517](https://github.com/melentev-av/opmin/commit/8f9d517b6db4069a1e162f63d1320da07edf2205))
* **verification:** drop an always-true condition of the string shrinker ([0a1dec4](https://github.com/melentev-av/opmin/commit/0a1dec47cee537a7e92cfa448c1b1948103ba469))
* **verification:** verify the files of one step together ([dd3fa0b](https://github.com/melentev-av/opmin/commit/dd3fa0b3c911934c64a33ecc69bc88fe28dbd0f3))


### Continuous Integration

* **release:** a feature bumps the minor version before 1.0.0 ([86b622f](https://github.com/melentev-av/opmin/commit/86b622f13c00321ce5ad0e6a125c68191f5f5a8d))

## 0.1.0 (2026-10-06)


### Features

* **cli:** register placeholders for all planned commands ([5339941](https://github.com/melentev-av/opmin/commit/533994149165b869629a600ed5137ea465f58676))
* **config:** yaml configuration with strict validation and json schema ([57adc5f](https://github.com/melentev-av/opmin/commit/57adc5f9e75201fa7a09b19e726c6dcba38f85e6))
* m0 project skeleton ([674daea](https://github.com/melentev-av/opmin/commit/674daea0b64b73d30e049b265129b522b9116564))
* **playground:** seeds and bin/playground scenarios ([5e3ac1f](https://github.com/melentev-av/opmin/commit/5e3ac1fab01ef5ae3af0b06a92a336c00b621727))


### Documentation

* add project brief ([ecad7e3](https://github.com/melentev-av/opmin/commit/ecad7e31a6529b075f0f0d570d826a4df9ae1e43))
* document commit message rules checked by commitlint ([5211111](https://github.com/melentev-av/opmin/commit/521111167dcdd78019d2d75916fa982e2002da7f))
* readme, changelog and agent guidelines ([375338c](https://github.com/melentev-av/opmin/commit/375338c2795f3d83cd79b060f45299d9dc71c24a))

## Changelog

All notable changes to this project are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions follow [Semantic Versioning](https://semver.org/).
Release sections are generated by release-please from Conventional Commits; until 1.0.0 a `feat` bumps the minor
version and a breaking change does not jump to 1.0.0.
