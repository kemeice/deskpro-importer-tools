Articles
==================

**Base article fields.**

| Column name               | Type    | Required | Possible values                                                                                             | Default | Description                                                                                                                             | Examples                                                          |
| --------------------------|---------|----------|-------------------------------------------------------------------------------------------------------------|---------|-----------------------------------------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------|
| oid                       | int     |  *       |                                                                                                             |         | External source id.                                                                                                                     |                                                                   |
| import_map_key            | string  |  *       |                                                                                                             |         | External source type. <br/><br/>  "oid" + "import_map_key" is the unique key for proper item updates when you try to re-import the item.| dp_article, zd_article, kayako_article                            |
| person                    | string  |  *       |                                                                                                             |         | Author email.                                                                                                                           | user@example.com                                                  |
| title                     | string  |  *       |                                                                                                             |         | Article title.                                                                                                                          |                                                                   |
| content                   | string  |  *       |                                                                                                             |         | Article content.                                                                                                                        |                                                                   |
| language                  | string  |  *       |                                                                                                             |         | Article language. You can use language name, locale or DeskPRO lang code.                                                               | eng, English, en_US                                               |
| slug                      | string  |          |                                                                                                             |         | Article slug.                                                                                                                           |                                                                   |
| status                    | string  |  *       | published <br/> archived <br/> hidden.unpublished <br/> hidden.deleted <br/> hidden.spam <br/> hidden.draft |         | Article status.                                                                                                                         |                                                                   |
| date_created              | string  |          |                                                                                                             | NOW()   | Article date created.                                                                                                                   | 2016-07-12 00:00:00                                               |
| date_published            | string  |          |                                                                                                             | NULL    | Article date published.                                                                                                                 | 2016-07-12 00:00:00                                               |
| date_updated              | string  |          |                                                                                                             | NULL    | Article date last updated.                                                                                                              | 2016-07-12 00:00:00                                               |
| date_end                  | string  |          |                                                                                                             | NULL    | Article date end.                                                                                                                       | 2016-07-12 00:00:00                                               |
| labels                    | array   |          |                                                                                                             | [ ]     | Article labels.                                                                                                                         | ["label 1", "label 2"]                                            |
| categories                | array   |          |                                                                                                             | [ ]     | Article categories.                                                                                                                     | ["Category 1 > Sub Category 1"]                                   |
| custom_fields             | array   |          |                                                                                                             | [ ]     | Article custom fields.                                                                                                                  |                                                                   |
| attachments               | array   |          |                                                                                                             | [ ]     | Article attachments.                                                                                                                    |                                                                   |
| comments                  | array   |          |                                                                                                             | [ ]     | Article comments.                                                                                                                       |                                                                   |
| translations              | array   |          |                                                                                                             | [ ]     | Article translations.                                                                                                                   |                                                                   |

**Translation fields.**

| Column name               | Type    | Required | Possible values                                                                                             | Default | Description                                                                                                                             | Examples                                                          |
| --------------------------|---------|----------|-------------------------------------------------------------------------------------------------------------|---------|-----------------------------------------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------|
| language                  | string  |  *       |                                                                                                             |         | Article translation language. You can use language name, locale or DeskPRO lang code.                                                   | eng, English, en_US                                               |
| property                  | string  |  *       | title <br/> content                                                                                         |         | Article translation property.                                                                                                           |                                                                   |
| value                     | string  |  *       |                                                                                                             |         | Article translation content.                                                                                                            |                                                                   |

**Comment fields.**

| Column name               | Type    | Required | Possible values                                                                                             | Default | Description                                                                                                                             | Examples                                                          |
| --------------------------|---------|----------|-------------------------------------------------------------------------------------------------------------|---------|-----------------------------------------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------|
| person_email              | string  |  *       |                                                                                                             |         | Author email.                                                                                                                           | user@example.com                                                  |
| content                   | string  |  *       |                                                                                                             |         | Article comment content.                                                                                                                |                                                                   |
| status                    | string  |  *       | visible <br/> deleted <br/> agent                                                                           |         | Article comment status.                                                                                                                 |                                                                   |
| is_reviewed               | boolean |          |                                                                                                             | false   | Is comment reviewed.                                                                                                                    |                                                                   |
| date_created              | string  |          |                                                                                                             | NOW()   | Article date created.                                                                                                                   | 2016-07-12 00:00:00                                               |

**Attachment fields.**

| Column name               | Type    | Required | Possible values                                                                                             | Default | Description                                                                                                                             | Examples                                                          |
| --------------------------|---------|----------|-------------------------------------------------------------------------------------------------------------|---------|-----------------------------------------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------|
| person                    | string  |  *       |                                                                                                             |         | Author email.                                                                                                                           | user@example.com                                                  |
| blob_data                 | string  |          |                                                                                                             |         | Blob data. You need to use one of "blob_data",  "blob_url" or "blob_path" fields to get blob data.                                      |                                                                   |
| blob_url                  | string  |          |                                                                                                             |         | Blob url.                                                                                                                               |                                                                   |
| blob_path                 | string  |          |                                                                                                             |         | Blob path.                                                                                                                              |                                                                   |
| file_name                 | string  | *        |                                                                                                             |         | Blob filename.                                                                                                                          |                                                                   |
| content_type              | string  | *        |                                                                                                             |         | Blob content type.                                                                                                                      |                                                                   |
| is_inline                 | boolean | *        |                                                                                                             | false   | Used for images in article content.                                                                                                     |                                                                   |

**Custom data fields.**

| Column name               | Type    | Required | Possible values                                                                                             | Default | Description                                                                                                                             | Examples                                                          |
| --------------------------|---------|----------|-------------------------------------------------------------------------------------------------------------|---------|-----------------------------------------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------|
| key                       | string  |  *       |                                                                                                             |         | Custom definition name.                                                                                                                 |                                                                   |
| value                     | string  |  *       |                                                                                                             |         | Custom data value.                                                                                                                      |                                                                   |

Custom data field values format

| Widget type | Value format |
| ------------|--------------|
| text        | string       |
| textarea    | string       |
| toggle      | true/false   |
| date        | Y-m-d        |
| datetime    | Y-m-d H:i:s  |
| choice      | int          |
| multichoice | int[]        |
| checkbox    | int[]        |
| radio       | int          |
| hidden      | string       |