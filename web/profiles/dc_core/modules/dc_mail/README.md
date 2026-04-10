# Decoupled Mail (dc_mail)

Custom mail handler for Decoupled.io that sends emails via **Resend HTTP API** instead of SMTP.

## Why HTTP API Instead of SMTP?

- ✅ **No SMTP port blocking** - Uses HTTPS (port 443) which is always open
- ✅ **No DigitalOcean ticket needed** - Bypasses SMTP port restrictions entirely
- ✅ **Faster delivery** - Direct HTTP requests instead of SMTP protocol overhead
- ✅ **Better error handling** - JSON responses with detailed error messages
- ✅ **Simpler architecture** - No msmtp, no sendmail replacement needed

## Security Model

**The Resend API key is NEVER stored in Drupal configuration or database.**

- ✅ API key read from `RESEND_API_KEY` environment variable
- ✅ Environment variable passed via Docker Compose
- ✅ Docker Compose reads from droplet's `.env` file (chmod 600)
- ✅ Not exposed in Drupal admin UI
- ✅ Not stored in any config export
- ✅ Not accessible to admin users via UI

**Admin users cannot:**
- View the API key in any Drupal configuration form
- Export the API key via Drush config-export
- Access the API key through the database
- Retrieve the API key through any Drupal API

## How It Works

1. **Module enabled** during dc_core profile installation
2. **Mail system configured** automatically in `dc_core_install()`
3. **Environment variable** `RESEND_API_KEY` read at runtime
4. **All Drupal emails** sent via Resend HTTP API:
   - Password resets
   - User registrations
   - Contact form submissions
   - Any `\Drupal::service('plugin.manager.mail')->mail()` call

## Configuration

### Environment Variable

Add to droplet's `/opt/drupalcloud/.env`:
```bash
RESEND_API_KEY=re_xxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

### Docker Compose

Already configured in `docker-compose.prod.yml`:
```yaml
environment:
  - RESEND_API_KEY=${RESEND_API_KEY:-}
```

### Terraform

Configured in `terraform/variables.tf` and `terraform/smtp.auto.tfvars`:
```hcl
resend_api_key = "re_xxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
```

## Testing

Test email sending from within the container:

```bash
# Create test script
cat > /tmp/test-drupal-mail.php << 'EOF'
<?php
use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

$autoloader = require_once '/var/www/html/vendor/autoload.php';
$request = Request::createFromGlobals();
$kernel = DrupalKernel::createFromRequest($request, $autoloader, 'prod');
$kernel->boot();
$kernel->preHandle($request);

$mailManager = \Drupal::service('plugin.manager.mail');
$result = $mailManager->mail(
  'system',
  'test',
  'josh@nextbigagency.com',
  'en',
  [
    'subject' => 'Test from Drupal Mail System',
    'body' => ['This email was sent via dc_mail using Resend HTTP API!'],
  ]
);

echo $result['result'] ? "✅ Email sent successfully!\n" : "❌ Email failed to send\n";
EOF

# Run test
docker compose -f /opt/drupalcloud/docker-compose.prod.yml exec -T drupal php /tmp/test-drupal-mail.php
```

## Troubleshooting

### Email not sending

**Check if RESEND_API_KEY is set:**
```bash
docker compose -f /opt/drupalcloud/docker-compose.prod.yml exec -T drupal bash -c 'echo $RESEND_API_KEY'
```

Should show: `re_xxxxxxxxxxxxxxxxxxxxxxxxxxxxx` (not empty)

**Check Drupal logs:**
```bash
docker compose -f /opt/drupalcloud/docker-compose.prod.yml exec -T drupal drush watchdog:show --type=dc_mail
```

**Check mail system configuration:**
```bash
docker compose -f /opt/drupalcloud/docker-compose.prod.yml exec -T drupal drush config:get system.mail interface.default
```

Should show: `resend_mail`

### Common Errors

**"RESEND_API_KEY environment variable not set"**
- Add `RESEND_API_KEY=re_xxx` to `/opt/drupalcloud/.env`
- Restart containers: `docker compose -f docker-compose.prod.yml up -d --force-recreate`

**"Resend API HTTP 401 error: Invalid token"**
- API key is wrong or expired
- Verify API key in Resend dashboard
- Update in `terraform/smtp.auto.tfvars`

**"Resend API HTTP 422 error"**
- Invalid email format or domain not verified
- Check that `from` email domain is verified in Resend
- For testing, use `@resend.dev` email addresses

## Files

- `dc_mail.info.yml` - Module definition
- `dc_mail.install` - Installation hooks
- `src/Plugin/Mail/ResendMail.php` - Mail plugin implementation

## API Reference

### Resend HTTP API

- **Endpoint:** `POST https://api.resend.com/emails`
- **Authentication:** `Bearer {RESEND_API_KEY}`
- **Documentation:** https://resend.com/docs/api-reference/emails/send-email

### Environment Variables

| Variable | Required | Description |
|----------|----------|-------------|
| `RESEND_API_KEY` | Yes | Resend API key (starts with `re_`) |

## License

GPL 2.0+
