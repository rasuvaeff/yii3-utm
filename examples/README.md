# Examples

Runnable scripts. No server or database is needed for anything listed here yet
— the domain layer is pure.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/domain-basics.php
```

| Script | Shows | Needs server? |
|---|---|---|
| `domain-basics.php` | Parsing untrusted query parameters into a campaign tuple, click ids and a touchpoint; building and collapsing history; classifying the channel | No |

Scripts for the capture middleware, the attribution service and the database
adapter will be added together with those layers.
