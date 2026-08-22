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

**Статус: функционально готово.** Для portable-хранения на `yiisoft/db`
используйте `rasuvaeff/yii3-utm-db`, реализацию приложения или поставляемый
in-memory репозиторий в тестах.

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

### Захват

Один middleware; поддерживаемые транспорты — это конфигурация, а не отдельные
классы.

```php
use Rasuvaeff\Yii3Utm\UtmCaptureMiddleware;

// web-пайплайн
UtmCaptureMiddleware::class,
```

```php
use Rasuvaeff\Yii3Utm\UtmRequest;

UtmRequest::current($request);    // ?UtmTouchpoint — касание этого запроса
UtmRequest::history($request);    // UtmHistory — сохранённая, может быть пустой
UtmRequest::effective($request);  // ?UtmTouchpoint — current ?? самое свежее сохранённое
```

Атрибуты выставляются всегда — код ниже по стеку никогда не различает
«middleware не отработал» и «нечего было захватывать».

| Транспорт | Источник | Для чего |
|---|---|---|
| Query string | `QueryUtmSource` | Server-rendered страницы; заодно landing page и `Referer` |
| Заголовки `X-Utm-*` | `HeaderUtmSource` | SPA и API-клиенты; click id передаются JSON-объектом в `X-Utm-Click-Ids` |
| Вложенный ключ `utm` в теле | `BodyUtmSource` | SPA и API; рекомендуемый cross-domain транспорт |

Все три источника отбрасывают referrer, совпадающий по host с самим текущим
запросом (`Referrer::external()`, не `Referrer::of()`): переход между
страницами своего же сайта — не касание, к которому стоит привязывать визит.

История живёт в **одной** cookie (по умолчанию `utm_history`), кодируется
`UtmCookieCodec`: `HttpOnly`, `Secure`, `SameSite=Lax`, 30 дней. Клиентский
профиль (`httpOnly: false`) существует для чтения из SPA на том же домене и по
определению подделываем. `DefaultLandingPageSanitizer` — поставляемая реализация — оставляет scheme, host,
порт и path, выбрасывает fragment и все query-параметры вне allow-list (по
умолчанию `utm_*` и click id) и обрезает до 500 символов.

Cookie считается недоверенным входом и на чтении: кодек прогоняет referrer и
landing page раскодированной записи через тот же санитайзер, так что
отредактированная руками cookie не занесёт в историю `javascript:`-URL или
несанированный landing page. `Referrer::of()` принимает только `http` и
`https`. Если вы настраиваете собственный allow-list — передайте свой
санитайзер и в `UtmCookieCodec`; поставляемый `config/di.php` уже это делает.

`UtmCookieCodec::$maxLength` (по умолчанию 3500) — размер
**percent-encoded** значения, то есть того, что уходит в `Set-Cookie`: кодек
выбрасывает самые старые касания, пока закодированное значение не поместится,
оставляя место имени cookie и её атрибутам внутри браузерного лимита 4096
байт.

`NullUtmHistoryStore` не хранит ничего — правильный
выбор для stateless-API и кэшируемых роутов: иначе захват добавляет
`Set-Cookie` и делает ответ некэшируемым.

| Опция | По умолчанию | Эффект |
|---|---|---|
| `enabled` | `true` | Общий выключатель |
| `ignoredPaths` | `[]` | Префиксы путей, которые пропускаются |
| `similarity` | `Full` | Что считать «той же кампанией» |
| `updateExisting` | `false` | Дописывать ли касание, похожее на последнее сохранённое |
| `captureOrganic` | `false` | Считать ли касанием визит без кампании и без click id |
| `maxTouchpoints` | `5` | Ограничение истории |
| `maxTouchpointAge` | 90 дней | Окно хранения для заявленного `occurredAt`: время из будущего прижимается к now, более старое не даёт касания и удаляет сохранённое |
| `clearHistoryWithoutConsent` | `false` | Гасить ли сохранённую историю при отсутствии согласия |

### Согласие

`ConsentPolicy::allowsPersistence()` управляет всем: без согласия ничего не
читается и ничего не пишется. По умолчанию — `AllowAllConsentPolicy`, для
приложений, где согласие проверяется раньше по стеку.

```php
use Rasuvaeff\Yii3Utm\CallbackConsentPolicy;

new CallbackConsentPolicy(
    static fn (ServerRequestInterface $r): bool => $consentBanner->accepted($r),
);
```

Имя метода совпадает с `rasuvaeff/yii3-ab-testing-web` — приложение, где
политика уже есть, переиспользует её одной строкой.

### Конфигурация

Пакет поставляет `config/di.php` и `config/params.php` для `yiisoft/config`.
Он биндит capture-стек, кодек, санитайзер, резолвер канала и дефолтную
политику согласия — и сознательно **не** биндит `UtmAttributionRepository`,
у которого должен быть ровно один источник.

Группа параметров `rasuvaeff/yii3-utm` открывает:

- `capture.sources.query.utmKeys` и `clickIdKeys`;
- `capture.sources.header.prefix` и `clickIdKeys`;
- `capture.sources.body.key` и `clickIdKeys`;
- `sanitizer.allowedQueryKeys` и `maxLength`;
- `channel.paidMediums`, `emailMediums`, `socialMediums`, `socialHosts` и
  `searchHosts`.

### Атрибуция

Бизнес-событие превращается в строку на каждое касание. `UtmAttribution` сам
вычисляет `fingerprint` и `dedupeKey` — они никогда не приходят аргументами
конструктора, потому что рассогласованный fingerprint молча ломает
unique-индекс журнала.

```php
use Rasuvaeff\Yii3Utm\{InteractionType, UtmAttributionEvent, UtmAttributionService};

$service = new UtmAttributionService($repository);   // репозиторий даёт -db или приложение

$service->record(new UtmAttributionEvent(
    entityId: (string) $user->getId(),
    eventId: $order->getUuid(),           // стабилен при retry, новый для нового события
    interactionType: InteractionType::purchase(),
    history: $history,
));   // возвращает число реально созданных строк
```

| Гарантия | Детали |
|---|---|
| Retry того же события | Не пишет ничего: дедуп по event id **и** касанию |
| Действительно новое событие | Пишет строки даже при идентичной кампании |
| Частичная запись | Самовосстанавливается: повторная доставка допишет недостающее и не продублирует существующее — поэтому пачка не обёрнута в транзакцию |
| Порядок | От старого касания к новому; канонический порядок назначает сервер при записи |
| Пустые касания | Пропускаются — строка, которая ничего не атрибутирует, это шум |

`UtmAttributionEventHandler` — готовый слушатель (`__invoke`), но пакет его не
подписывает: проводка — решение приложения.

### Хранение

`UtmAttributionRepository` — контракт хранения: `append()`, `findByEntity()`,
`findFirst()`, `findLast()`, `countByEntity()`, `deleteByEntity()`,
`purgeOlderThan()`, `countOlderThan()` (что удалил бы `purgeOlderThan()`, без
удаления — для dry-run). Ядро **его не биндит**: реализацию даёт
`rasuvaeff/yii3-utm-db` либо приложение. `InMemoryUtmAttributionRepository` поставляется для тестов и возвращает
`InMemoryUtmAttributionRecord`; в контейнере не биндится.


Реализация обязана делать `append()` race-safe — upsert без действия при
конфликте либо insert с обработкой duplicate key. «Проверить, потом вставить» —
недостаточно.

## Безопасность

| Аспект | Поведение |
|---|---|
| Клиентский вход | Недостоверен: нормализуется, обрезается, невалидное становится `null`. Значения остаются произвольным текстом — экранирование при выводе на стороне потребителя |
| `occurredAt` | Заявление источника, не доказательство времени визита |
| Порядок | Назначает сервер; поздняя доставка не может стать первым касанием |
| Дедуп | Fingerprint и dedupe key вычисляются, извне не принимаются |
| Referrer | Только `http`/`https`; в fingerprint входит только хост |
| Landing page | Обрезается до 500 символов; санитайзинг query выполняется до хранения |
| Cookie | При декодировании санируется ровно как query string, а её размер считается после percent-encoding |

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
