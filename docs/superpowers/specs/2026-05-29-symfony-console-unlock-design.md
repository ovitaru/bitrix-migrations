# Разблокировка symfony/console 4–8 и совместимость с мажорами

**Дата:** 2026-05-29
**Статус:** реализовано, верифицировано локально (PHP 8.4)
**Целевая ветка PR (base):** `fix-and-improve-v2` (создана от `fix-and-improve`)
**Рабочая ветка (head):** `claude/sweet-meitner-nmfm2`

## Цель

Снять верхний кап `symfony/console` и разрешить версии `^4 || ^5 || ^6 || ^7 || ^8`,
убрать конфликтную зависимость `illuminate/support`, подтянуть dev-зависимости и CI
под современные PHP, сохранив максимально широкую совместимость по PHP.

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
| Нижняя граница PHP | `^7.1 \|\| ^8.0` (поднята с `^7.0`, см. §2 — вынужденно из-за Symfony 7/8) |
| phpunit / CI | phpunit `^9 \|\| ^10 \|\| ^11`, GitHub Actions с матрицей PHP×Symfony, `.travis.yml` удалить |

## Изменения

### 1. composer.json

- `symfony/console`: `~2|~3|~4|~5` → `^4 || ^5 || ^6 || ^7 || ^8`
- `illuminate/support`: **удалить** из `require`
- `php`: `^7.0 || ^8.0` → `^7.1 || ^8.0` (обоснование в §2)
- `phpunit/phpunit` (dev): `^7 || ^8.0 || ^9.0` → `^9 || ^10 || ^11`
- `mockery/mockery` (dev): без изменений (`^1`)
- `phpstan/phpstan` (dev): оставить `^1.10`
- `scripts.test`: без изменений

### 2. Блокеры Symfony 7/8 — `$defaultName` и return-types

**2.1. `$defaultName`.** В каждой из 7 команд: удалить `protected static $defaultName = '<name>';`
и в начало `configure()` поставить `$this->setName('<name>')->setDescription(...)`.
`setName()` в `configure()` работает во всех версиях Symfony 2–8; `configure()` вызывается из
конструктора `Command::__construct`, поэтому имя проставлено к моменту `$app->add(...)`.
Атрибуты `#[AsCommand]` не используются (синтаксис атрибутов — только PHP 8).

**2.2. Return-types (найдено при верификации).** Symfony 8 объявляет нативные типы у методов,
которые мы переопределяем:
`Command::execute(): int`, `Command::configure(): void` (а также `interact(): void`,
`initialize(): void` — их мы не переопределяем). Без совпадения сигнатур — фатал
«Declaration must be compatible». Поэтому:
- `AbstractCommand::execute()` → `execute(...): int`;
- `configure()` в 7 командах → `configure(): void`.

В Symfony 4/5/6 у родителя этих типов нет → добавление типа в наследнике допустимо.
Синтаксис `: void` требует **PHP 7.1+**, поэтому нижняя граница PHP поднята до `^7.1`
(PHP 7.0 EOL с 2018 и с Symfony 8 принципиально несовместим). `: int` — PHP 7.0+, но
флор всё равно определяется `: void`.

### 3. Удаление illuminate — инлайн `collect()`

- `StatusCommand::showOldMigrations()`: `->count()` → `count($x)`;
  `->take(-$max)` → `array_slice($x, -$max)`; далее обычный `foreach`.
- `StatusCommand::showNewMigrations()`: убрать `collect()`, `foreach` напрямую по массиву.
- `TemplatesCommand::collectRows()`:
  `->filter($fn)` → `array_filter($templates, $fn)`;
  `->sortBy('name')` → `usort($templates, function ($a, $b) { return $a['name'] <=> $b['name']; })`;
  `->map($fn)` → `array_map($fn, $templates)`.
  `separateRows()` уже работает с обычным массивом — менять не нужно.

Источники (`Migrator::getRanMigrations()`, `getMigrationsToRun()` через `array_diff`,
`TemplatesCollection::all()`) возвращают массивы — инлайн безопасен.

### 4. Доп. фиксы совместимости (найдено при верификации)

- `Migrator::__construct`: `DatabaseStorageInterface $database = null` →
  `?DatabaseStorageInterface $database = null` и аналогично `?FileStorageInterface $files`.
  Убирает deprecation PHP 8.4 «implicitly nullable» (фатал в PHP 9.0). `?Type` — PHP 7.1+.
- `RollbackCommand::markRolledBackWithConfirmation`: перед `$helper = $this->getHelper('question')`
  добавлен `@var \Symfony\Component\Console\Helper\QuestionHelper`. В Symfony 6+ `getHelper()`
  объявлен как `HelperInterface` (без `ask()`) → phpstan-ошибка; аннотация уточняет реальный тип,
  рантайм не меняется.

### 5. phpunit.xml + .gitignore

- `phpunit.xml`: минимальная схема, валидная для phpunit 9/10/11 — оставить только
  `bootstrap`, `colors` и блок `<testsuites>`; убрать `convert*ToExceptions`,
  `backupStaticAttributes`, `backupGlobals`, `processIsolation`, `stopOnFailure`
  (дефолтные значения подходят, неизвестные атрибуты ломают схему phpunit 10+).
- `.gitignore`: добавить `.phpunit.cache` (директория кэша phpunit 10/11).

### 6. CI: GitHub Actions

- Удалить `.travis.yml`, создать `.github/workflows/ci.yml`.

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
→ `composer update --prefer-dist` → `vendor/bin/phpunit`.
phpunit-версия подбирается composer'ом автоматически по PHP (7.4→9, …, 8.4→11).

**Job `static-analysis`**: PHP **8.3** → composer ставит symfony/console 7.x → `vendor/bin/phpstan analyse`.
PHP 8.3 (не 8.4) выбран сознательно: phpstan 1.12 не парсит исходники symfony/console 8 (синтаксис
PHP 8.4) и «теряет» методы `Command`; на symfony 7 анализ корректен. baseline регенерировать **не
требуется** — правки не задели его «Bitrix-записи», а новые находки исправлены в коде (см. §4).

### 7. Ветки и PR

- `fix-and-improve-v2` (создана пользователем от `fix-and-improve`) — **база PR**.
- Работа на `claude/sweet-meitner-nmfm2` (переставлена на `fix-and-improve`).
- PR: base `fix-and-improve-v2` ← head `claude/sweet-meitner-nmfm2`.

## Верификация (выполнено локально, PHP 8.4.19)

- `composer update` без illuminate резолвится: **symfony/console v8.1.0**, phpunit 11.5.55,
  mockery 1.6.12, phpstan 1.12.33.
- `phpunit`: **9/9 OK**, 0 deprecations — на symfony/console **8** и (после `--with`) **7**.
- `phpstan analyse`: **[OK] No errors** на symfony/console 7 (level 5 + baseline).
- Полная совместимость 4–8 на разных PHP — доказывается матрицей GitHub Actions.

## Вне зоны (YAGNI)

- Строгую типизацию добавляем только в обязательном объёме (return-types `execute()/configure()`,
  явные `?Type` — этого требуют Symfony 7/8 и PHP 8.4).
- Не трогаем Bitrix-специфичную логику (`Autocreate/*`, `Constructors/*`, `Traits/*`).
- Не поднимаем phpstan до `^2.0` (1.12 работает на выбранной для анализа связке PHP 8.3 + symfony 7).
