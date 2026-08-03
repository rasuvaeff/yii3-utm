# Examples

Runnable scripts. No server or database is needed for the core examples.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/domain-basics.php
```

| Script | Shows | Needs server? |
|---|---|---|
| `domain-basics.php` | Parsing untrusted query parameters into a campaign tuple, click ids and a touchpoint; building and collapsing history; classifying the channel | No |
| `attribution-journal.php` | Recording a business event, idempotent redelivery, first/last touch, retention and personal-data erasure — against the in-memory repository | No |
| `capture-pipeline.php` | Driving `UtmCaptureMiddleware` through an ad click, a return visit, a plain page view and a request without consent; cookie flags and channel classification | No |
| `spa-body-transport.js` | Capturing a versioned UTM payload in browser `localStorage` and producing the nested body expected by `BodyUtmSource` | Browser |

Browser usage:

```js
import {captureUtm, utmRequestBody} from './spa-body-transport.js';

captureUtm();
await fetch('/register', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({...registrationData, ...utmRequestBody()}),
});
```

The payload is untrusted convenience state, not evidence. PHP sanitizes and
clamps every field again.

The database adapter ships its own runnable `examples/sqlite-journal.php` in
`rasuvaeff/yii3-utm-db`.
