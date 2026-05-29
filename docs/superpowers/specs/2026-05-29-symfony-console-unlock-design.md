# Разблокировка symfony/console 4–8 и совместимость с мажорами

**Дата:** 2026-05-29
**Статус:** утверждён дизайн, ожидается ревью спеки
**Целевая ветка PR (base):** `fix-and-improve-v2` (создаётся от `fix-and-improve`)
**Рабочая ветка (head):** `claude/sweet-meitner-nmfm2`

## Цель

Снять верхний кап `symfony/console` и разрешить версии `^4 || ^5 || ^6 || ^7 || ^8`,
убрать конфликтную зависимость `illuminate/support`, подтянуть dev-зависимости и CI
под современные PHP — **сохранив совместимость с PHP 7**.

## Контекст / исходное состояние (ветка `fix-and-improve`)

- `composer.json`: `symfony/console: ~2|~3|~4|~5`, `illuminate/support: ~5|~6|~7|~8`,
  `php: ^7.0 || ^8.0`, dev: `phpunit ^7|^8|^9`, `mockery ^1`, `phpstan ^1.10`,
  `scripts.test = [phpstan, phpunit]`.
- 7 команд (`Migrate/Rollback/Make/Status/Archive/Templates/Install`) используют
  `protected static $defaultName` — механизм **удалён в Symfony 7.0**.
- `illuminate/support` используется **только** ради глобального `collect()` в 3 местах
  (`StatusCommand` ×2, `TemplatesCommand` ×1); методы `count/take/filter/sortBy/map`.
- Тесты: чистые (нет удалённых в phpunit 10/11 API, `tearDown(): void` уже типизирован),
  но `phpunit.xml` использует старую схему (`convert*ToExceptions`, `backupStaticAttributes`),
  которую phpunit 10+ не принимает.
- CI: `.travis.yml` (PHP 5.6–7.3, Travis мёртв), GitHub Actions нет.
- phpstan: `phpstan.dist.neon` (level 5, paths `src/`, bleedingEdge + baseline),
  `phpstan-baseline.neon` — содержит только «unknown Bitrix-классы».

## Решения (подтверждены пользователем)

| Вопрос | Решение |
|---|---|
| Диапазон symfony/console | `^4 \|\| ^5 \|\| ^6 \|\| ^7 \|\| ^8` (2/3 — EOL, убрать) |
| illuminate/support | **удалить**, `collect()` заинлайнить в `array_*` |
| Нижняя граница PHP | оставить `^7.0 \|\| ^8.0` (код остаётся PHP7-safe, без атрибутов `#[AsCommand]`) |
| phpunit / CI | phpunit `^9 \|\| ^10 \|\| ^11`, GitHub Actions с матрицей PHP×Symfony, `.travis.yml` удалить |

## Изменения

### 1. composer.json

- `symfony/console`: `~2|~3|~4|~5` → `^4 || ^5 || ^6 || ^7 || ^8`
- `illuminate/support`: **удалить** из `require`
- `php`: без изменений (`^7.0 || ^8.0`)
- `phpunit/phpunit` (dev): `^7 || ^8.0 || ^9.0` → `^9 || ^10 || ^11`
- `mockery/mockery` (dev): без изменений (`^1`)
- `phpstan/phpstan` (dev): оставить `^1.10`; поднять только если потребуется для запуска на выбранной в CI версии PHP
- `scripts.test`: без изменений

### 2. Фикс главного блокера Symfony 7/8 — `$defaultName`

В каждой из 7 команд:
- удалить строку `protected static $defaultName = '<name>';`
- в начало метода `configure()` добавить `$this->setName('<name>');` (перед `setDescription(...)`)

Обоснование: `setName()` в `configure()` работает во всех версиях Symfony 2–8; `configure()`
вызывается из конструктора `Command::__construct`, поэтому имя проставлено к моменту
`$app->add(new Command(...))`. Атрибуты `#[AsCommand]` не используются (синтаксис атрибутов —
только PHP 8, а мы держим PHP 7).

Сигнатуры `execute()` / `fire()` **не меняются**: они уже возвращают `int`, у родительского
`Command::execute()` нет return-type ни в одной версии (несовместимости нет); `: int` не
добавляем, чтобы не рисковать ковариантностью на PHP 7.0.

### 3. Удаление illuminate — инлайн `collect()`

- `StatusCommand::showOldMigrations()`: `collect($x)`; `->count()` → `count($x)`;
  `->take(-$max)` → `array_slice($x, -$max)`; далее обычный `foreach`.
- `StatusCommand::showNewMigrations()`: убрать `collect()`, `foreach` напрямую по массиву.
- `TemplatesCommand::collectRows()`:
  `->filter($fn)` → `array_filter($templates, $fn)`;
  `->sortBy('name')` → `usort($templates, fn($a,$b) => $a['name'] <=> $b['name'])`;
  `->map($fn)` → `array_map($fn, $templates)`.
  `separateRows()` уже работает с обычным массивом — менять не нужно.

Источники (`Migrator::getRanMigrations()`, `getMigrationsToRun()` через `array_diff`,
`TemplatesCollection::all()`) возвращают массивы — инлайн безопасен.

### 4. phpunit.xml + .gitignore

- `phpunit.xml`: минимальная схема, валидная для phpunit 9/10/11 — оставить только
  `bootstrap`, `colors` и блок `<testsuites>`; убрать `convert*ToExceptions`,
  `backupStaticAttributes`, `backupGlobals`, `processIsolation`, `stopOnFailure`
  (дефолтные значения подходят, неизвестные атрибуты ломают схему phpunit 10+).
- `.gitignore`: добавить `.phpunit.cache` (директория кэша phpunit 10/11).

### 5. CI: GitHub Actions

- Удалить `.travis.yml`.
- Создать `.github/workflows/ci.yml`.

**Версии:** PHP 5.6 и 7.0–7.3 не тестируем (EOL); 7.4 — представитель PHP 7.
Матрица только из валидных пар PHP×Symfony (`include`):

| PHP | symfony/console |
|---|---|
| 7.4 | `^4`, `^5` |
| 8.0 | `^5`, `^6` |
| 8.1 | `^5`, `^6` |
| 8.2 | `^6`, `^7` |
| 8.3 | `^7` |
| 8.4 | `^7`, `^8` |

Каждый мажор 4–8 покрыт минимум одной парой; невозможные пары (например, Symfony 8 на
PHP < 8.4) исключены.

**Job `tests`** (матрица): `shivammathur/setup-php` → `composer require symfony/console:<X> --no-update`
→ `composer update --prefer-dist --no-interaction` → `vendor/bin/phpunit`.
phpunit-версия подбирается composer'ом автоматически по PHP (7.4→9, …, 8.4→11).

**Job `static-analysis`** (PHP 8.4, highest deps): `composer install` → `vendor/bin/phpstan analyse`.
`phpstan-baseline.neon` перегенерировать на той же связке (PHP 8.4 + разрешённый phpstan),
чтобы записи baseline совпадали и job был зелёным.

### 6. Ветки и PR

- `fix-and-improve-v2` создаётся от `fix-and-improve` — **база PR**. Ветку создаёт сам
  пользователь (в сессии нет write-доступа к `ovitaru/bitrix-migrations`: git-push новой
  ветки и MCP `create_branch` вернули 403).
- Работа ведётся на `claude/sweet-meitner-nmfm2` (переставлена на `fix-and-improve`).
- PR: base `fix-and-improve-v2` ← head `claude/sweet-meitner-nmfm2`.

## Верификация

- Локально (PHP 8.4): `composer update` с `symfony/console:^7` и `^8`; прогон `phpunit` и `phpstan`.
- Проверка резолва composer для `^4`/`^5`/`^6` (через `--with` / временный require).
- Финально — зелёная матрица GitHub Actions (доказательство совместимости 4–8).

## Вне зоны (YAGNI)

- Не рефакторим сигнатуры команд и не добавляем строгую типизацию (держим PHP 7).
- Не трогаем Bitrix-специфичный код (`Autocreate/*`, `Constructors/*`, `Traits/*`).
- Не поднимаем phpstan до `^2.0`, если `^1.10` запускается на выбранной версии PHP.
