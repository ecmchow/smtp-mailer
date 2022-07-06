<p align="center"><img src="../../docs/mailer.svg?raw=true" width="128"></p>

<h3 align="center">SMTP Mailer</h3>

<p align="center">
    API documentation
    <br />
    <a href="../../README.md"><strong>Back to Home »</strong></a>
    <br />
</p>

<!-- TABLE OF CONTENTS -->
<details>
  <summary>Table of Contents</summary>
  <ol>
    <li><a href="#introduction">Introduction</a></li>
    <li><a href="#authentication">Authentication</a></li>
    <li><a href="#service-status-api">Service Status</a></li>
    <li><a href="#mail-sending-api">Mail Sending API</a></li>
    <li><a href="#mail-queuing/scheduling-api">Mail Queuing/Scheduling API</a></li>
    <li>
      <a href="#queue-management-api">Queue Management API</a>
      <ul>
        <li><a href="#retrieve-current-queue">Retrieve current queue</a></li>
        <li><a href="#retrieve-queued-mail-content">Retrieve queued mail content</a></li>
        <li><a href="#update-queued-mail-content">Update queued mail content</a></li>
        <li><a href="#remove-queued-mail-content">Remove queued mail content</a></li>
        <li><a href="#clear-all-queued-mails">Clear all queued mails</a></li>
      </ul>
    </li>
    <li>
      <a href="#template-management-api">Template Management API</a>
      <ul>
        <li><a href="#retrieve-all-mail-template">Retrieve all mail template</a></li>
        <li><a href="#retrieve-mail-template">Retrieve mail template</a></li>
        <li><a href="#add-mail-template">Add mail template</a></li>
        <li><a href="#update-mail-template">Update mail template</a></li>
        <li><a href="#remove-mail-template">Remove mail template</a></li>
        <li><a href="#clear-all-mail-template">Clear all mail template</a></li>
      </ul>
    </li>
  </ol>
</details>

<br/>

## Introduction

SMTP Mailer accepts TCP request with JSON encoded payload. All payload will pass through basic validation while mail sending request will be validated against a [JSON schema](../../Core/schema/sendMail.json).

<br/>

## Authentication

If you have enabled the optional API password authentication in env, you should append the password with `auth` as key in the payload.

```json
{
    "sendMail": {},
    "auth": "password"
}
```

## Service Status API

| Name | Type | Description |
| :--- | :--- | :--- |
| `ping` | `any` | Ping service  |

```json
{
    "ping": null
}
```

Success Response
```json
{
   "status": "success",
   "data": null,
   "message": "pong"
}
```

| Name | Type | Description |
| :--- | :--- | :--- |
| `status` | `any` | Get service status |

Status API is only available when any of the following conditions are met:
* SMTP Mailer is running in single worker mode
* Redis integration is enabled

```json
{
    "status": null
}
```

Success Response
```json
{
   "status": "success",
   "data": {
       "sent": 10,
       "failed": 1
   },
   "message": null
}
```

Failed Response
```json
{
   "status": "success",
   "data": null,
   "message": "Redis store or running in single worker is required"
}
```

<br/>

## Mail Sending API

| Name | Type | Description |
| :--- | :--- | :--- |
| `sendMail` | `object` | Mail content. See below parameters  |

Sending SMTP mail with specified body content
```json
{
    "sendMail": {
        "to": ["user@domain.com"],
        "ccList": [],
        "bccList": [
            ["name@domain.com", "Person Name"]
        ],
        "attachments": [
            ["/path/to/file", "doc.pdf"]
        ],
        "embedded": [
            ["/path/to/img", "logo", "logo.png"]
        ],
        "subject": "This is subject",
        "body": "<html>This is content</html>"
    }
}
```

Sending SMTP mail with specified template and replace string
(assuming you have {{NAME}} and {{DATE}} template string in template HTML)
```json
{
    "sendMail": {
        "to": ["user@domain.com"],
        "ccList": [],
        "bccList": [],
        "subject": "This is subject",
        "useTemplate": "myTemplate.html",
        "replaceContent": {
            "NAME": "myName",
            "DATE": "XXXX-XX-XX"
        }
    }
}
```

| Parameter | Type | Description |
| :--- | :--- | :--- |
| `to` | `array` | **Required**. Mail recipients. Items can be string of address or array of [address, name]  |
| `ccList` | `array` | **Required**. CC recipients. Items can be string of address or array of [address, name]  |
| `bccList` | `array` | **Required**. BCC recipients. Items can be string of address or array of [address, name]  |
| `replyTo` | `array` | **Optional**. Reply-To recipients. Items can be string of address or array of [address, name]  |
| `attachments` | `array` | **Optional**. Attachments list. Items must be array of [filePath, fileName] |
| `embedded` | `array` | **Optional**. Embedded image list. Items must be array of [filePath, cid, fileName] |
| `subject` | `string` | **Required**. Mail subject. It will be encoded in base64 format automatically |
| `body` | `string` | **Required** if not using `useTemplate`. Mail Body. Plaintext or HTML string |
| `useTemplate` | `string` | **Required** if not using `body`. Template file name you wish to use |
| `replaceContent` | `object` | **Optional**. Replace template string in template file called by `useTemplate` |
| `fromEmail` | `string` | **Optional**. Override default FROM email address in env |
| `fromName` | `string` | **Optional**. Override default FROM name in env |
| `smtpHost` | `string` | **Optional**. Override default SMTP host in env |
| `smtpUser` | `string` | **Optional**. Override default SMTP user in env |
| `smtpPassword` | `string` | **Optional**. Override default SMTP password in env |
| `smtpEncryption` | `string` | **Optional**. Override default SMTP encryption method in env |
| `smtpPort` | `string` | **Optional**. Override default SMTP port in env |
| `timeout` | `int` | **Optional**. Override default SMTP timeout in env |

Success Response
```json
{
   "status": "success",
   "data": null,
   "message": "mail sent successfully"
}
```

Failed Response
```json
{
   "status": "error",
   "data": "SMTP Error: Could not connect to SMTP host.",
   "message": "failed to send mail"
}
```

<br/>

## Mail Queuing/Scheduling API

| Name | Type | Description |
| :--- | :--- | :--- |
| `queueMail` | `object` | Mail content. See below parameters  |

Adding mail to current queue
```json
{
    "queueMail": {
        "to": ["user@domain.com"],
        "ccList": [],
        "bccList": [
            ["name@domain.com", "Person Name"]
        ],
        "attachments": [
            ["/path/to/file", "doc.pdf"]
        ],
        "embedded": [
            ["/path/to/img", "logo", "logo.png"]
        ],
        "subject": "This is subject",
        "body": "<html>This is content</html>"
    }
}
```

Adding mail to queue with scheduled send time
```json
{
    "queueMail": {
        "scheduleTime": 1651596430,
        "to": ["user@domain.com"],
        "ccList": [],
        "bccList": [],
        "subject": "This is subject",
        "body": "<html>This is content</html>"
    }
}
```

| Parameter | Type | Description |
| :--- | :--- | :--- |
| `scheduleTime` | `int` | **Optional**. Scheduled send time (number of seconds since the Unix Epoch) |
| ... | ... | ... |
including parameters from above `sendMail` API

Success Response
```json
{
   "status": "success",
   "data": null,
   "message": "mail added to queue"
}
```

Failed Response
```json
{
   "status": "error",
   "data": null,
   "message": "failed to add mail to queue"
}
```

<br/>

## Queue Management API

**Notes:** File-based queue will follow a naming format of `mail_{time}.{microtime}_{random}.json` while Redis-based queue will follow a key naming format of `{time}.{microtime}_{random}` (excluding prefix)

### Retrieve current queue

| Name | Type | Description |
| :--- | :--- | :--- |
| `getQueueList` | `int` or `null` | Number of maximum display items (Default/maximum: 500) |

Retrieve current queue with default limit
```json
{
    "getQueueList": null
}
```

Retrieve current queue with maximum 100 items and pagination (zero-indexed)
```json
{
    "getQueueList": {
        "limit": 100,
        "page": 0
    }
}
```

Response
```json
{
   "status": "success",
   "data": {
       "items": [
           "mail_0000000001657084500.39823600_9e51fa6fad25da44.json",
           "mail_0000000001657084601.30733100_eb307331d0674bbe.json",
           "mail_0000000001657085540.80923300_bb38809234216d3a.json"
        ],
       "total": 3
   },
   "message": "found 3 mails in queue"
}
```

### Retrieve queued mail content

| Name | Type | Description |
| :--- | :--- | :--- |
| `getQueuedMail` | `string` | mail JSON filename |


```json
{
    "getQueuedMail": "mail_0000000001657084500.39823600_9e51fa6fad25da44.json"
}
```

Response (SMTP password will not be included in returned data)
```json
{
   "status": "success",
   "data": {
        "to": ["user@domain.com"],
        "ccList": [],
        "bccList": [],
        "attachments": [],
        "embedded": [],
        "subject": "This is subject",
        "body": "<html>This is content</html>"
   },
   "message": "mail_0000000001657084500.39823600_9e51fa6fad25da44.json"
}
```

### Update queued mail content

| Name | Type | Description |
| :--- | :--- | :--- |
| `updateQueuedMail` | `string` | mail JSON filename |
| `content` | `object` | any of the parameters from above `sendMail` API |

```json
{
    "updateQueuedMail": "mail_0000000001657084500.39823600_9e51fa6fad25da44.json",
    "content": {
        "subject": "New subject"
    }
}
```

Response (SMTP password will not be included in returned data)
```json
{
    "status": "success",
    "data": {
        "to": ["user@domain.com"],
        "ccList": [],
        "bccList": [],
        "attachments": [],
        "embedded": [],
        "subject": "New subject",
        "body": "<html>This is content</html>"
    },
    "message": {
        "original": "mail_0000000001657084500.39823600_9e51fa6fad25da44.json",
        "updated": "mail_0000000001657084500.39823600_16d3aaea9d835eb3.json"
    }
}
```

### Remove queued mail content

| Name | Type | Description |
| :--- | :--- | :--- |
| `removeQueuedMail` | `string` | mail JSON filename |

```json
{
    "removeQueuedMail": "mail_0000000001657084500.39823600_9e51fa6fad25da44.json"
}
```

Response
```json
{
   "status": "success",
   "data": "mail_0000000001657084500.39823600_9e51fa6fad25da44.json",
   "message": "queued mail removed"
}
```

### Clear all queued mails

| Name | Type | Description |
| :--- | :--- | :--- |
| `clearQueue` | `any` | ... |

```json
{
    "clearQueue": null
}
```

Response
```json
{
   "status": "success",
   "data": null,
   "message": "removed 3 mails in queue"
}
```


<br/>

## Template Management API

**Notes:** Template filename `{name}.html` is for reference only, you can name them freely with valid non-system filename or as Redis key name

### Retrieve all mail template

| Name | Type | Description |
| :--- | :--- | :--- |
| `getTemplateList` | `int` or `null` | Number of maximum display items (Default/maximum: 500) |

Retrieve all mail template with default limit
```json
{
    "getTemplateList": null
}
```

Retrieve all mail template with maximum 100 items and pagination (zero-indexed)
```json
{
    "getTemplateList": {
        "limit": 100,
        "page": 0
    }
}
```

Response
```json
{
   "status": "success",
   "data": {
       "items": [
           "template-mail-01.html",
           "template-mail-02.html",
           "template-mail-03.html"
        ],
       "total": 3
   },
   "message": "found 3 templates"
}
```

### Retrieve mail template

| Name | Type | Description |
| :--- | :--- | :--- |
| `getTemplate` | `string` | template filename |


```json
{
    "getTemplate": "template-mail-01.html"
}
```

Response
```json
{
   "status": "success",
   "data": "<html>This is template content</html>",
   "message": "template found"
}
```

### Add mail template

| Name | Type | Description |
| :--- | :--- | :--- |
| `addTemplate` | `string` | template filename |
| `content` | `object` | any of the parameters from above `sendMail` API |

```json
{
    "addTemplate": "template-mail-04.html",
    "content": "<html>This is template content</html>"
}
```

Response
```json
{
   "status": "success",
   "data": "template-mail-04.html",
   "message": "template added"
}
```

### Update mail template

| Name | Type | Description |
| :--- | :--- | :--- |
| `updateTemplate` | `string` | template filename |
| `content` | `object` | any of the parameters from above `sendMail` API |

```json
{
    "updateTemplate": "template-mail-04.html",
    "content": "<html>This is new template content</html>"
}
```

Response
```json
{
   "status": "success",
   "data": "template-mail-04.html",
   "message": "template updated"
}
```

### Remove mail template

| Name | Type | Description |
| :--- | :--- | :--- |
| `removeTemplate` | `string` | template filename |

```json
{
    "removeTemplate": "template-mail-04.html"
}
```

Response
```json
{
   "status": "success",
   "data": "template-mail-04.html",
   "message": "template removed"
}
```

### Clear all mail template

| Name | Type | Description |
| :--- | :--- | :--- |
| `clearTemplate` | `any` | ... |

```json
{
    "clearTemplate": null
}
```

Response
```json
{
   "status": "success",
   "data": null,
   "message": "removed 3 templates"
}
```