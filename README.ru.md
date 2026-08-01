# rasuvaeff/yii3-utm

[![Stable Version](https://poser.pugx.org/rasuvaeff/yii3-utm/v)](https://packagist.org/packages/rasuvaeff/yii3-utm)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-utm/downloads)](https://packagist.org/packages/rasuvaeff/yii3-utm)
[![Build](https://github.com/rasuvaeff/yii3-utm/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-utm/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/yii3-utm/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-utm/actions/workflows/static-analysis.yml)
[![Psalm level](https://shepherd.dev/github/rasuvaeff/yii3-utm/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-utm)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

Маркетинговая атрибуция для Yii3-приложений: захват UTM-меток, рекламных click
id и referrer, короткая история касаний и append-only журнал атрибуции для
регистраций, покупок и любых других бизнес-событий.

> Используете AI-ассистента? [llms.txt](llms.txt) — компактный справочник API
> для LLM. Пакет также раздаёт agent skill через
> [llm/skills](https://github.com/roxblnfk/skills).

**Статус: в работе.** Доменный слой, описанный ниже, реализован и покрыт
тестами; capture-middleware, сервис атрибуции и адаптер хранения
`rasuvaeff/yii3-utm-db` добавляются следующими.

## Требования

- PHP 8.3 – 8.5
- `ext-json`, `ext-mbstring`

## Установка

```bash
composer require rasuvaeff/yii3-utm
```

## Почему журнал, а не колонка

Между кликом по объявлению и покупкой проходят дни и несколько визитов. Одна
колонка «текущая UTM» отвечает не на тот вопрос. Пакет хранит последние касания
и в момент бизнес-события пишет по строке на каждое касание — поэтому
first-touch, last-touch и мультитач-модели остаются вычислимыми позже.

Три инварианта задают весь API:

1. Всё, что присылает клиент — query, заголовки, тело, cookie, `localStorage` —
   недостоверно. Значения нормализуются, обрезаются или отбрасываются, но
   никогда не аутентифицируются.
2. Журнал append-only, порядок задаёт **сервер**. Клиент не может сделать так,
   чтобы поздняя доставка стала первым касанием.
3. Дедуп работает на уровне касания внутри одного бизнес-события: retry того же
   события не пишет ничего нового, а действительно новое событие пишет.

## Использование

### Параметры кампании

`UtmParameters` — кортеж кампании: пять стандартных `utm_*` плюс GA4 `utm_id`.
Фабрики нормализуют недоверенный вход: control-символы вырезаются, значения
обрезаются до 255 символов, пустые строки становятся `null`.

```php
use Rasuvaeff\Yii3Utm\UtmParameters;

$utm = UtmParameters::fromArray($request->getQueryParams());

$utm->source;    // 'google'
$utm->content;   // 'banner-a' — попадает в content, а не в campaign
$utm->isEmpty(); // false
$utm->toArray(); // стабильные snake_case-ключи, round-trip безопасен
```

### Click id

Платформы с auto-tagging присылают click id и ни одного `utm_*` — Google Ads
шлёт голый `gclid`. `ClickIds` принимает только ключи из whitelist, в порядке
whitelist, и ограничивает длину сериализованной формы шириной колонки хранения.

```php
use Rasuvaeff\Yii3Utm\ClickIds;

$ids = ClickIds::fromArray($request->getQueryParams());

$ids->get('gclid');  // 'EAIaIQobChMI...'
$ids->isEmpty();     // false
$ids->toJson();      // {"gclid":"..."} — детерминированный порядок ключей
```

Поддерживаемые ключи: `gclid`, `gbraid`, `wbraid`, `fbclid`, `yclid`, `ttclid`,
`msclkid`, `li_fat_id`, `twclid`.

### Касания и история

`UtmTouchpoint` — одно касание: кортеж кампании, click id, referrer, landing
page и время, заявленное источником. `UtmHistory` хранит их от свежих к старым.

```php
use Rasuvaeff\Yii3Utm\{Referrer, UtmHistory, UtmSimilarity, UtmTouchpoint};

$touchpoint = UtmTouchpoint::of(
    utm: $utm,
    occurredAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
    clickIds: $ids,
    referrer: Referrer::of('https://ads.example.com/'),
    landingPage: 'https://shop.example.com/summer',
);

$history = UtmHistory::of($touchpoint)
    ->deduplicated(UtmSimilarity::Campaign)  // оставляет самое старое в группе
    ->limited(5);                            // оставляет пять самых свежих

$history->latest();
$history->oldest();
```

| Метод | Поведение |
|---|---|
| `UtmHistory::of(...$touchpoints)` | Сортирует от свежих к старым, ничьи разрешаются детерминированно |
| `with(UtmTouchpoint)` | Возвращает новую историю с добавленным касанием |
| `deduplicated(UtmSimilarity)` | Схлопывает похожие касания, оставляя **самое старое** в группе |
| `limited(int)` | Оставляет не более N свежих касаний |
| `latest()` / `oldest()` / `all()` / `count()` / `isEmpty()` | Чтение |

`UtmSimilarity` задаёт, что считать похожим: `Full` (кортеж кампании и click
id), `Campaign` (source, medium, campaign) или `SourceMedium`.

### Типы взаимодействия

Какие бизнес-события существуют — решает приложение, поэтому тип не enum, а
валидируемая строка:

```php
use Rasuvaeff\Yii3Utm\InteractionType;

InteractionType::registration();
InteractionType::purchase();
InteractionType::of('trial_started');   // /^[a-z][a-z0-9_]{0,31}\z/
```

### Классификация канала

`Channel` вычисляется на чтении и сознательно не хранится — правила
классификации меняются чаще, чем допускает мажорная версия.

```php
use Rasuvaeff\Yii3Utm\{Channel, DefaultChannelResolver};

$channel = (new DefaultChannelResolver())->resolve($touchpoint);
// Channel::Paid — click id старше всех остальных правил
```

Порядок правил: click id → `utm_medium` → хост referrer. Словари (paid-, email-
и social-medium'ы, social- и search-хосты) задаются аргументами конструктора.

## Безопасность

| Аспект | Поведение |
|---|---|
| Клиентский вход | Недостоверен: нормализуется, обрезается, невалидное становится `null` |
| `occurredAt` | Заявление источника, не доказательство времени визита |
| Порядок | Назначает сервер; поздняя доставка не может стать первым касанием |
| Дедуп | Fingerprint и dedupe key вычисляются, извне не принимаются |
| Referrer | В fingerprint входит только хост; санитайзинг URL — задача capture-слоя |
| Landing page | Обрезается до 500 символов; санитайзинг query выполняется до хранения |

## Примеры

Исполняемые скрипты — в [examples/](examples/).

## Разработка

```bash
make build          # полный gate: validate, normalize, require-checker, cs, psalm, test
make cs-fix
make psalm
make test
make test-coverage
make mutation
make release-check
```

Без Make те же цели запускаются через Docker — см. [AGENTS.md](AGENTS.md).

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
