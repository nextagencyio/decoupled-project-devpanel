# DC Import Module

The DC Import module provides a REST API for importing Drupal content types and content from JSON data. This module allows you to programmatically create content types, fields, and content through a simple JSON-based API.

## API Endpoints

### POST `/api/decoupled-import`
Import content types and content from JSON data.

**Authentication Required:** Decoupled Drupal personal access token via `X-DC-Token` header.

### GET `/api/decoupled-import/status`
Get service status and API documentation.

**Authentication:** None required.

### GET `/dc-test`
Test endpoint for service health check.

**Authentication:** None required.

## Usage

### Basic Import Request

```bash
curl -X POST https://your-site.com/api/decoupled-import \
  -H "Content-Type: application/json" \
  -H "X-DC-Token: dc_tok_YOUR_ACCESS_TOKEN" \
  -d @import-data.json
```

### Preview Mode

Test your import without making actual changes by adding the `preview` parameter:

```bash
curl -X POST "https://your-site.com/api/decoupled-import?preview=true" \
  -H "Content-Type: application/json" \
  -H "X-DC-Token: dc_tok_YOUR_ACCESS_TOKEN" \
  -d @import-data.json
```

## JSON Data Structure

The API expects JSON data with the following structure:

```json
{
  "model": [
    {
      "bundle": "event",
      "description": "Content type for managing events, conferences, and gatherings",
      "label": "Event",
      "body": true,
      "fields": [
        {
          "id": "event_date",
          "label": "Event Date",
          "type": "datetime"
        },
        {
          "id": "location",
          "label": "Location",
          "type": "string"
        },
        {
          "id": "event_details",
          "label": "Event Details",
          "type": "paragraph(event_detail)[]"
        },
        {
          "id": "tags",
          "label": "Tags",
          "type": "term(tags)[]"
        },
        {
          "id": "featured",
          "label": "Featured",
          "type": "bool"
        }
      ]
    },
    {
      "entity": "paragraph",
      "bundle": "event_detail",
      "description": "Reusable content blocks for event information and details",
      "label": "Event Detail",
      "fields": [
        {
          "id": "detail_title",
          "label": "Detail Title",
          "type": "string!"
        },
        {
          "id": "detail_content",
          "label": "Detail Content",
          "type": "text"
        },
        {
          "id": "detail_image",
          "label": "Detail Image",
          "type": "image"
        }
      ]
    }
  ],
  "content": [
    {
      "id": "detail1",
      "type": "paragraph.event_detail",
      "values": {
        "detail_title": "Schedule",
        "detail_content": "The event will run from 9:00 AM to 5:00 PM with lunch break at noon."
      }
    },
    {
      "id": "detail2",
      "type": "paragraph.event_detail",
      "values": {
        "detail_title": "Speakers",
        "detail_content": "Join us for presentations by industry experts and thought leaders."
      }
    },
    {
      "id": "event1",
      "type": "node.event",
      "path": "/events/web-dev-conference-2024",
      "values": {
        "title": "Web Development Conference 2024",
        "body": "<p>Join us for a full day of learning about modern web development...</p>",
        "event_date": "2024-03-15T09:00:00",
        "location": "Convention Center Downtown",
        "tags": [
          "web-development",
          "conference",
          "technology"
        ],
        "featured": true,
        "event_details": ["@detail1", "@detail2"]
      }
    }
  ]
}
```

### Required Fields

#### Model Array
- `bundle`: Machine name of the content type
- `label`: Human-readable name of the content type

#### Fields Array (within model items)
- `id`: Machine name of the field
- `label`: Human-readable name of the field
- `type`: Field type (string, datetime, paragraph(), term(), etc.)

#### Content Array (Optional)
- `id`: Unique identifier for the content item
- `type`: Content type in format "entity.bundle" (e.g., "node.event", "paragraph.event_detail")
- `values`: Object containing field values for the content item

## Response Format

### Success Response

```json
{
  "success": true,
  "preview": false,
  "operations": 3,
  "messages": {
    "summary": [
      "Created content type: Article",
      "Created field: field_summary",
      "Created content: Sample Article"
    ],
    "warnings": []
  }
}
```

### Error Response

```json
{
  "success": false,
  "error": "Invalid JSON structure. Expected 'model' and optionally 'content' arrays."
}
```

## Field Types Supported

The module supports various Drupal field types:

- `text` - Single line text
- `text_long` - Multi-line text
- `text_with_summary` - Text with summary
- `string` - Plain text string
- `integer` - Integer number
- `decimal` - Decimal number
- `boolean` - Boolean (true/false)
- `email` - Email address
- `telephone` - Phone number
- `link` - URL link
- `image` - Image upload
- `file` - File upload
- `datetime` - Date and time
- `daterange` - Date range
- `entity_reference` - Reference to other entities
- `list_string` - Select list (text options)
- `list_integer` - Select list (integer options)

## Authentication

### Getting an Access Token

1. **Enable OAuth 2.0 modules** in your Drupal site
2. **Create an OAuth client** at `/admin/config/services/consumer`
3. **Grant permissions** to users who need API access:
   - `import dc config` - Required for import endpoint and UI access
4. **Obtain access token** using OAuth 2.0 flow

### Example Token Request

```bash
curl -X POST https://your-site.com/oauth/token \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "grant_type=client_credentials&client_id=YOUR_CLIENT_ID&client_secret=YOUR_CLIENT_SECRET"
```

## Error Codes

- `400 Bad Request` - Invalid JSON or missing required fields
- `401 Unauthorized` - Missing or invalid authentication
- `403 Forbidden` - Insufficient permissions
- `405 Method Not Allowed` - Wrong HTTP method (use POST)
- `500 Internal Server Error` - Server-side processing error

## Examples

### Creating a Simple Page Content Type

```json
{
  "model": [
    {
      "bundle": "page",
      "label": "Basic Page",
      "description": "Use basic pages for your static content, such as an 'About us' page.",
      "fields": [
        {
          "name": "body",
          "label": "Body",
          "type": "text_with_summary",
          "required": false
        }
      ]
    }
  ]
}
```

### Creating Content with References

```json
{
  "model": [
    {
      "bundle": "product",
      "label": "Product",
      "fields": [
        {
          "name": "field_price",
          "label": "Price",
          "type": "decimal",
          "required": true,
          "settings": {
            "precision": 10,
            "scale": 2
          }
        },
        {
          "name": "field_category",
          "label": "Category",
          "type": "entity_reference",
          "target_type": "taxonomy_term",
          "target_bundle": "product_categories"
        }
      ]
    }
  ],
  "content": [
    {
      "bundle": "product",
      "title": "Laptop Computer",
      "field_price": 999.99,
      "field_category": "electronics",
      "status": 1
    }
  ]
}
```

## Testing

### Check Service Status

```bash
curl https://your-site.com/api/decoupled-import/status
```

### Test Import (Preview Mode)

```bash
curl -X POST "https://your-site.com/api/decoupled-import?preview=true" \
  -H "Content-Type: application/json" \
  -H "X-DC-Token: dc_tok_YOUR_TOKEN" \
  -d '{"model":[{"bundle":"test","label":"Test Content Type"}]}'
```

## Troubleshooting

### Common Issues

1. **401 Unauthorized**
   - Verify your access token is valid
   - Check token hasn't expired
   - Ensure user has `import dc config` permission

2. **400 Bad Request - Invalid JSON**
   - Validate JSON syntax using a JSON validator
   - Ensure required fields (`bundle`, `label`) are present in model items

3. **500 Internal Server Error**
   - Check Drupal logs at `/admin/reports/dblog`
   - Verify all required modules are enabled
   - Check field type compatibility

### Debug Mode

Enable preview mode to test imports without making changes:

```bash
curl -X POST "https://your-site.com/api/decoupled-import?preview=true" \
  -H "Content-Type: application/json" \
  -H "X-DC-Token: dc_tok_YOUR_TOKEN" \
  -d @your-import-file.json
```

## Module Dependencies

- `node` - Core node module
- `field` - Core field module  
- `text` - Core text field module
- `options` - Core options field module
- `link` - Core link field module
- `image` - Core image field module
- `datetime` - Core datetime field module
- `datetime_range` - Core datetime range module
- `paragraphs` - Paragraphs module
- `entity_reference_revisions` - Entity reference revisions module

## Administration

### Web Interface

Access the administrative interface at:
`/admin/config/content/decoupled-import`

**Required Permission:** `import dc config`

### Permissions

Grant import access to users at:
`/admin/people/permissions`

Available permissions:
- **Import DC Configuration** - Required for both UI and API access to import content types and configuration
- **Access DC Import API** - Legacy permission (deprecated, use "Import DC Configuration" instead)