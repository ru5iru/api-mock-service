# Licensing and third-party notices

## Current application license

From the license-change commit dated 2026-10-21, MockDeck's application code and first-party documentation are offered under the [PolyForm Strict License 1.0.0](../LICENSE). The copyright holder is [Rusiru Rathmina](https://github.com/ru5iru). Composer uses `proprietary`, its supported identifier for non-open-source licensing, because PolyForm Strict 1.0.0 is not in the SPDX license list; the exact terms are in `LICENSE`.

MockDeck is **source-available**, not open-source software under the Open Source Definition. PolyForm Strict permits the noncommercial purposes and organization uses specified in its terms. It does not grant permission to distribute the software or make changes or new works based on it. Commercial application, including commercial QA/CI use, requires separate permission unless independently authorized. Statutory rights, including fair use where applicable, are unaffected. The full license controls over this summary.

For permission beyond these terms, contact the copyright holder through the [repository owner's GitHub profile](https://github.com/ru5iru). No additional commercial, modification or redistribution license is implied by the source being publicly visible or by setup/development instructions in the documentation.

## Previously released MIT versions

The license change does not revoke rights already granted for copies previously released under MIT. Commit `b10e7a3` (the parent of this license-change commit) and earlier MIT-released versions remain available under their original MIT terms. Those rights cover the code supplied in those versions; they do not automatically cover later changes offered only under PolyForm Strict.

The original copyright and MIT permission notice is preserved verbatim in [the historical MIT notice](../licenses/MIT-HISTORICAL.txt). That file documents the earlier grant; it is not an alternative MIT license for the current application. Where a file or portion remains separately licensed, its applicable notice still governs that material.

## Third-party material

This change applies only to material the MockDeck copyright holder is authorized to license. It does not relicense third-party code, assets or fonts, remove their attribution, or restrict rights granted by their own licenses.

| Material | Applicable license / notice |
| --- | --- |
| Laravel, Livewire, Laravel MCP, Faker and other Composer dependencies | Their own package licenses and copyright notices, retained with installed packages under `vendor/`; consult each package and `composer.lock`. |
| Other locked Composer dependencies | Include MIT, BSD-3-Clause and Apache-2.0 material. Nette's packages offer BSD/GPL alternatives; their BSD option remains available. Preserve applicable notices and any required attribution when distributing those packages. |
| Bundled JetBrains Mono font | [SIL Open Font License 1.1 and upstream copyright notice](../public/fonts/jetbrains-mono/OFL.txt). The font is not covered by MockDeck's PolyForm license. |
| Docker images and their bundled software | Their respective upstream licenses. The application license does not replace image or operating-system package licenses. |

Dependency installation and packaging must continue retaining upstream license files. This document describes the licensing boundary; it is not a replacement for each dependency's full terms or an exhaustive container-image notice inventory.

## Validation

`make frontend-test` (also run by `make validate` and GitHub CI) verifies the official PolyForm text's integrity, the application attribution, Composer's non-open-source license metadata, the historical MIT notice and the bundled font's retained notice. It also checks that README and in-app help explain the same licensing scope.
