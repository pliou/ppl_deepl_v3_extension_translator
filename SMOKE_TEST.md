# PPL DeepL V3 Extension Translator Smoke Test

## Install

1. Install or activate `ppl_deepl_v3_requests` first.
2. Install `ppl_deepl_v3_extension_translator`.
3. Clear TYPO3 caches.
4. Confirm the backend module appears at `PPL DeepL V3 > Extension Translator`.

## Static Safety

- Open the module without submitting anything.
- Confirm the header shows the current environment and DeepL configuration state.
- Confirm no DeepL request is made before using `Create DeepL suggestion`.

## Taxonomy Smoke Test

1. Activate or place `ppl_et_issue_fixture` beside the translator extension.
2. Run `vendor/bin/typo3 ppl:extension-translator:smoke --reset-fixture --run-matrix`.
3. Confirm the summary is written under `var/smoke/extension-translator-taxonomy/<timestamp>/summary.md`.
4. Confirm Fake DeepL calls are logged in `fake-deepl-calls.json` and no real API request is made.

## Vendor Safety Test

1. Scan one concrete package path such as `vendor/vendor/package`.
2. Confirm broad `vendor/` and `vendor/vendor` scans are rejected.
3. Confirm findings from vendor paths are read-only and cannot be selected for write.

## Write Smoke Test

1. Use `ppl_et_issue_fixture` or another disposable local test extension.
2. Select rows from one issue type only.
3. Choose one issue-specific action such as `Create TODO target suggestion` or `Create copy-source suggestion`.
4. Confirm the suggestion preview appears.
5. Confirm affected files, append/update counts and backup target are shown.
6. Tick `I reviewed the generated XLF changes`.
7. Run `Write suggestion to XLF`.
8. Confirm a backup exists under `var/ppl_deepl_v3_extension_translator/backups/`.
9. Re-scan and confirm the row state changed.

## DeepL Smoke Test

1. Select one safe writable row with source text.
2. Use an issue type with known source text, such as `missing_target`.
3. Run `Create DeepL suggestion`.
4. Confirm the translation preview appears and no write happened yet.
5. Run `Write suggestion to XLF` only after preview confirmation.
6. Confirm DeepL is accessed only through `ppl_deepl_v3_requests`.
