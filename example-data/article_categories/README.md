Article categories
==================

| Column name               | Type    | Required | Possible values | Default | Description                                                                                                                             | Examples                                                          |
| --------------------------|---------|----------|-----------------|---------|-----------------------------------------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------|
| oid                       | int     |  *       |                 |         | External source id.                                                                                                                     |                                                                   |
| import_map_key            | string  |  *       |                 |         | External source type. <br/><br/>  "oid" + "import_map_key" is the unique key for proper item updates when you try to re-import the item.| dp_article_category, zd_article_category, kayako_article_category |
| title                     | string  |  *       |                 |         | Category title.                                                                                                                         |                                                                   |
| is_agent                  | boolean |          |                 | false   |                                                                                                                                         |                                                                   |
| is_book                   | boolean |          |                 | false   |                                                                                                                                         |                                                                   |
| user_groups               | array   |          |                 | [ ]     | Array of DeskPRO usergroup sys names.                                                                                                   | everyone, registered                                              |
| categories                | array   |          |                 | [ ]     | Children categories. Array of sub categories in the same format as the parent one.                                                      |                                                                   |