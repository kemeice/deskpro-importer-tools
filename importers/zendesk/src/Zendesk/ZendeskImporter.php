<?php

namespace DeskPRO\ImporterTools\Importers\Zendesk;

use DeskPRO\ImporterTools\AbstractImporter;

/**
 * Class ZendeskImporter.
 */
class ZendeskImporter extends AbstractImporter
{
    /**
     * @var ZendeskReader
     */
    private $reader;

    /**
     * @var \DateTime
     */
    private $startTime;

    /**
     * @var string
     */
    private $ticketBrandField;

    /**
     * {@inheritdoc}
     */
    public function init(array $config)
    {
        $this->reader = new ZendeskReader($this->logger);
        $this->reader->setConfig($config['account']);

        $this->startTime        = new \DateTime($config['start_time']);
        $this->ticketBrandField = !empty($config['ticket_brand_field']) ? $config['ticket_brand_field'] : null;
    }

    /**
     * {@inheritdoc}
     */
    public function testConfig()
    {
        // try to make an api request to make sure that provided credentials are correct
        $this->reader->getOrganizationFields();
    }

    /**
     * {@inheritdoc}
     */
    public function runImport()
    {
        //--------------------
        // Custom definitions
        //--------------------

        $customDefMapper = function(array $data) {
            $customDef = [
                'title'           => $data['title_in_portal'] ?: $data['title'],
                'description'     => $data['description'],
                'is_enabled'      => $data['active'],
                'is_user_enabled' => $data['active'],
                'is_agent_field'  => !$data['visible_in_portal'],
                'widget_type'     => ZendeskMapper::$customFieldWidgetTypeMapping[$data['type']],
            ];

            foreach (['system_field_options', 'custom_field_options'] as $optionGroup) {
                if (!empty($data[$optionGroup])) {
                    foreach ($data[$optionGroup] as $option) {
                        $customDef['choices'][] = [
                            'title' => $option['value'],
                        ];
                    }
                }
            }

            return $customDef;
        };

        $this->progress()->startOrganizationCustomDefImport();
        foreach ($this->reader->getOrganizationFields() as $n) {
            $this->writer()->writeOrganizationCustomDef($n['id'], $customDefMapper($n));
        }

        $this->progress()->startPersonCustomDefImport();
        foreach ($this->reader->getPersonFields() as $n) {
            $this->writer()->writePersonCustomDef($n['id'], $customDefMapper($n));
        }

        $this->progress()->startTicketCustomDefImport();
        $ticketCustomDefMap = [];
        foreach ($this->reader->getTicketFields() as $n) {
            $customDef                    = $customDefMapper($n);
            $ticketCustomDefMap[$n['id']] = $customDef['title'];

            $this->writer()->writeTicketCustomDef($n['id'], $customDef);
        }

        //--------------------
        // Organizations
        //--------------------

        $this->progress()->startOrganizationImport();
        foreach ($this->reader->getOrganizations() as $n) {
            $organization = [
                'name'         => $n['name'],
                'date_created' => $n['created_at'],
                'labels'       => $n['tags'],
            ];

            // custom fields
            foreach ($n['organization_fields'] as $c) {
                if (!$c['value']) {
                    continue;
                }

                $organization['custom_fields'][] = [
                    'oid'   => $c['id'],
                    'value' => $c['value'],
                ];
            }

            $this->writer()->writeOrganization($n['id'], $organization);
        }

        //--------------------
        // People
        //--------------------

        $this->progress()->startPersonImport();
        $pager = $this->reader->getPersonPager($this->startTime);

        foreach ($pager as $n) {
            // user could have empty email, add auto generated one
            if (!$n['email']) {
                $n['email'] = 'imported.user.'.$n['id'].'@example.com';
            }

            $person = [
                'name'         => $n['name'],
                'emails'       => [$n['email']],
                'date_created' => $n['created_at'],
                'organization' => $n['organization_id'],
            ];

            if (isset(ZendeskMapper::$timezoneMapping[$n['time_zone']])) {
                $person['timezone'] = ZendeskMapper::$timezoneMapping[$n['time_zone']];
            } else {
                $person['timezone'] = 'UTC';
                $this->output()->warning("Found unknown timezone `{$n['time_zone']}`");
            }

            // custom fields
            foreach ($n['user_fields'] as $c) {
                if (!$c['value']) {
                    continue;
                }

                $person['custom_fields'][] = [
                    'oid'   => $c['id'],
                    'value' => $c['value'],
                ];
            }

            if ($n['role'] === 'admin') {
                $person['is_admin'] = true;
                $this->writer()->writeAgent($n['id'], $person, false);
            } elseif ($n['role'] === 'agent') {
                $this->writer()->writeAgent($n['id'], $person, false);
            } else {
                $this->writer()->writeUser($n['id'], $person, false);
            }
        }

        //--------------------
        // Tickets
        //--------------------

        $this->progress()->startTicketImport();
        $statusMapping = [
            'new'     => 'awaiting_agent',
            'open'    => 'awaiting_agent',
            'pending' => 'awaiting_user',
            'hold'    => 'awaiting_agent',
            'solved'  => 'resolved',
            'closed'  => 'resolved',
            'deleted' => 'hidden.deleted',
        ];

        $pager = $this->reader->getTicketPager($this->startTime);
        foreach ($pager as $n) {
            $ticket = [
                'subject'      => $n['subject'] ?: 'No Subject',
                'status'       => $statusMapping[$n['status']],
                'person'       => $n['requester_id'],
                'agent'        => $n['assignee_id'],
                'labels'       => $n['tags'],
                'organization' => $n['organization_id'],
                'date_created' => $n['created_at'],
                'participants' => $n['collaborator_ids'],
            ];

            // custom fields
            foreach ($n['custom_fields'] as $c) {
                if (!$c['value']) {
                    continue;
                }

                if (isset($ticketCustomDefMap[$c['id']]) && $ticketCustomDefMap[$c['id']] === $this->ticketBrandField) {
                    $ticket['brand'] = $c['value'];
                } else {
                    $ticket['custom_fields'][] = [
                        'oid'   => $c['id'],
                        'value' => $c['value'],
                    ];
                }
            }

            // messages
            foreach ($this->reader->getTicketComments($n['id']) as $c) {
                $message = [
                    'oid'     => $c['id'],
                    'person'  => $c['author_id'],
                    'message' => $c['body'],
                    'is_note' => !$c['public'],
                ];

                foreach ($c['attachments'] as $a) {
                    $blobData = $this->attachments()->loadAttachment($a['content_url']);
                    if (!$blobData) {
                        continue;
                    }

                    $message['attachments'][] = [
                        'oid'          => $a['id'],
                        'file_name'    => $a['file_name'],
                        'content_type' => $a['content_type'],
                        'blob_data'    => $blobData,
                    ];
                }

                $ticket['messages'][] = $message;
            }

            $this->writer()->writeTicket($n['id'], $ticket);
        }

        //--------------------
        // Article categories
        //--------------------

        $this->progress()->startArticleCategoryImport();

        $sections = [];
        foreach ($this->reader->getArticlesSections() as $n) {
            $accessPolicy = $n['access_policy'];

            $sections[$n['category_id']][] = [
                'oid'         => $n['id'],
                'title'       => $n['name'],
                'user_groups' => $accessPolicy['viewable_by'] === 'everybody' ? ['everyone'] : ['registered'],
                'is_agent'    => $accessPolicy['viewable_by'] === 'staff',
            ];
        }

        foreach ($this->reader->getArticlesCategories() as $n) {
            $category = [
                'title'      => $n['name'],
                'categories' => isset($sections[$n['id']]) ? $sections[$n['id']] : [],
            ];

            $this->writer()->writeArticleCategory($n['id'], $category);
        }

        //--------------------
        // Articles
        //--------------------

        $this->progress()->startArticleImport();
        $pager = $this->reader->getArticlePager($this->startTime);

        foreach ($pager as $n) {
            $article = [
                'person'       => $n['author_id'],
                'title'        => $n['title'],
                'content'      => $n['body'],
                'categories'   => [$n['section_id']],
                'labels'       => $n['label_names'],
                'date_created' => $n['created_at'],
                'date_updated' => $n['updated_at'],
                'language'     => ZendeskMapper::getLanguageByLocale([$n['locale']]),
                'status'       => $n['draft'] ? 'hidden.draft' : 'published',
            ];

            // comments
            foreach ($this->reader->getArticleComments($n['id']) as $c) {
                $article['comments'][] = [
                    'oid'          => $c['id'],
                    'content'      => $c['body'],
                    'person'       => $c['author_id'],
                    'date_created' => $c['created_at'],
                    'status'       => 'visible',
                ];
            }

            // attachments
            foreach ($this->reader->getArticleAttachments($n['id']) as $a) {
                $blobData = $this->attachments()->loadAttachment($a['content_url']);
                if (!$blobData) {
                    continue;
                }

                $article['attachments'][] = [
                    'oid'          => $a['id'],
                    'file_name'    => $a['file_name'],
                    'content_type' => $a['content_type'],
                    'blob_data'    => $blobData,
                ];
            }

            // translations
            foreach ($this->reader->getArticleTranslations($n['id']) as $t) {
                $language = ZendeskMapper::getLanguageByLocale([$t['locale']]);
                if (!$language) {
                    continue;
                }

                $article['title_translations'][] = [
                    'language' => $language,
                    'value'    => $t['title'],
                ];

                $article['content_translations'][] = [
                    'language' => $language,
                    'value'    => $t['title'],
                ];
            }

            if ($n['draft']) {
                $article['status'] = 'hidden.draft';
            } else {
                $article['status'] = 'published';
            }

            $this->writer()->writeArticle($n['id'], $article);
        }
    }
}
