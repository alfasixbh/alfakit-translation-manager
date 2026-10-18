# Changelog

All notable changes to `alfasix/alfakit-translation-manager` are documented in this file.

## Unreleased

- Initial release: a `Translations` page with a keyboard-driven grid (search, status and group filters, server pagination, plural-form inputs, right-to-left inputs, placeholder/HTML/plural warnings, progress per language, history per key), a cached database override layer installed in front of Laravel's translation loader (`database`, `files` and `both` modes), atomic export to PHP/JSON lang files with backups, import from files, "Add language", a source scanner and `alfakit-translations:scan|export|import` commands, reviewed AI translations through laravel/ai or a bound `MachineTranslator`, tenant-scoped overrides, and `authorize()` / `manageTranslations` gate authorisation (denied by default).
