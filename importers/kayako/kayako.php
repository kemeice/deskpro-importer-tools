<?php

########################################################################################################################
# CONFIG
########################################################################################################################

require __DIR__.'/config.php';

########################################################################################################################
# Do not edit below this line
########################################################################################################################

use DeskPRO\ImporterTools\Helpers\OutputHelper;
use DeskPRO\ImporterTools\Helpers\WriteHelper;
use DeskPRO\ImporterTools\Helpers\FormatHelper;
use DeskPRO\ImporterTools\Helpers\DbHelper;

//--------------------
// Setup
//--------------------

$output    = OutputHelper::getHelper();
$writer    = WriteHelper::getHelper();
$formatter = FormatHelper::getHelper();

$db = DbHelper::getHelper();
$db->setCredentials($CONFIG['dbinfo']);

//--------------------
// Organizations
//--------------------

$output->startSection('Organizations');
$pager = $db->getPager('SELECT * FROM swuserorganizations');

foreach ($pager as $n) {
    $organization = [
        'name'         => $n['organizationname'],
        'date_created' => date('c', $n['dateline']),
    ];

    // set organization contact data
    // website
    if ($formatter->getFormattedUrl($n['website'])) {
        $organization['contact_data']['website'][] = [
            'url' => $formatter->getFormattedUrl($n['website']),
        ];
    }

    // phone numbers
    if ($formatter->getFormattedNumber($n['phone'])) {
        $organization['contact_data']['phone'][] = [
            'number' => $formatter->getFormattedNumber($n['phone']),
            'type'   => 'phone',
        ];
    }
    if ($formatter->getFormattedNumber($n['fax'])) {
        $organization['contact_data']['phone'][] = [
            'number' => $formatter->getFormattedNumber($n['fax']),
            'type'   => 'fax',
        ];
    }

    // address
    if ($n['address']) {
        $organization['contact_data']['address'][] = [
            'address' => $n['address'],
            'city'    => $n['city'],
            'zip'     => $n['postalcode'],
            'state'   => $n['state'],
            'country' => $n['country'],
        ];
    }

    $writer->writeOrganization($n['userorganizationid'], $organization);
}


//--------------------
// Staff
//--------------------

$output->startSection('Staff');

$staffEmailsMapping = [];
$staffGroups        = [];
$staffGroupsMapping = [
    'Administrator' => 'All Permissions',
    'Staff'         => 'All Non-Destructive Permissions',
];


$pager = $db->getPager('SELECT * FROM swstaffgroup');
foreach ($pager as $n) {
    if (isset($staffGroupsMapping[$n['title']])) {
        $staffGroups[$n['staffgroupid']] = $staffGroupsMapping[$n['title']];
    } else {
        $staffGroups[$n['staffgroupid']] = $n['title'];
    }
}


$pager = $db->getPager('SELECT * FROM swstaff');
foreach ($pager as $n) {
    $staffId = $n['staffid'];
    $email   = $n['email'];
    if (!$formatter->isEmailValid($email)) {
        $email = 'imported.agent.'.$staffId.'@example.com';
    }

    $person = [
        'name'        => $n['fullname'],
        'emails'      => [$email],
        'is_disabled' => !$n['isenabled'],
    ];

    if ($n['staffgroupid']) {
        $person['agent_groups'][] = $staffGroups[$n['staffgroupid']];
    }
    if (isset($person['agent_groups']) && in_array('All Permissions', $person['agent_groups'])) {
        $person['is_admin'] = true;
    }

    $writer->writeAgent($staffId, $person);
    $staffEmailsMapping[$email] = $staffId;
}

//--------------------
// Users
//--------------------

$output->startSection('Users');

$userGroups        = [];
$userGroupsMapping = [
    'Guest' => 'Everyone',
];

$pager = $db->getPager('SELECT * FROM swusergroups');
foreach ($pager as $n) {
    if (isset($userGroupsMapping[$n['title']])) {
        $userGroups[$n['usergroupid']] = $userGroupsMapping[$n['title']];
    } else {
        $userGroups[$n['usergroupid']] = $n['title'];
    }
}

// we can have end-user and agent with same email address so prepare mapping their ids mapping by email
$userAgentsMapping = [];

$pager = $db->getPager('SELECT * FROM swusers');
foreach ($pager as $n) {
    $userId = $n['userid'];

    $userEmails = [];
    $emailsRows = $db->findAll('SELECT * FROM swuseremails WHERE linktypeid = :user_id', ['user_id' => $userId]);

    foreach ($emailsRows as $emailsRow) {
        $email = $emailsRow['email'];
        if ($formatter->isEmailValid($email)) {
            $userEmails[] = $email;

            // check for agent with the same email address
            if (isset($staffEmailsMapping[$email])) {
                $userAgentsMapping[$userId] = $staffEmailsMapping[$email];

                // ignore writing user as we already have the agent with this email address
                continue 2;
            }
        }
    }

    if (empty($userEmails)) {
        $userEmails[] = 'imported.user.'.$userId.'@example.com';
    }

    $person = [
        'name'         => $n['fullname'] ?: $userEmails[0],
        'emails'       => $userEmails,
        'is_disabled'  => !$n['isenabled'],
        'organization' => $n['userorganizationid'],
        'date_created' => date('c', $n['dateline']),
    ];

    if ($n['usergroupid'] && isset($userGroups[$n['usergroupid']])) {
        $person['user_groups'][] = $userGroups[$n['usergroupid']];
    }

    if ($person['organization']) {
        $person['organization_position'] = $n['userdesignation'];
    }

    $phone = $formatter->getFormattedNumber($n['phone']);
    if ($phone) {
        $person['contact_data']['phone'][] = [
            'number' => $phone,
            'type'   => 'phone',
        ];
    }

    $writer->writeUser($userId, $person);
}

//--------------------
// Tickets and messages
//--------------------

$output->startSection('Tickets');
$statusMapping = [
    'Open'          => 'awaiting_agent',
    'In Progress'   => 'awaiting_agent',
    'With Engineer' => 'awaiting_agent',
    'Answered'      => 'awaiting_user',
    'On Hold'       => 'on_hold',
    'Overdue'       => 'awaiting_agent',
    'Resolved'      => 'resolved',
    'Closed'        => 'resolved',
];

// prepare a map of ticket departments
$ticketDepartmentMapping    = [];
$getTicketDepartments = function ($parentId, $parentTitle = '') use ($db, &$ticketDepartmentMapping, &$getTicketDepartments) {
    $pager = $db->findAll('SELECT * FROM swdepartments WHERE parentdepartmentid = :parent_id', [
        'parent_id' => $parentId,
    ]);

    foreach ($pager as $n) {
        $id    = $n['departmentid'];
        $title = $parentTitle.$n['title'];

        $ticketDepartmentMapping[$id] = $title;
        $getTicketDepartments($id, $title.' > ');
    }
};

$getTicketDepartments(0);

// get tickets
$pager = $db->getPager('SELECT * FROM swtickets');
foreach ($pager as $n) {
    if (isset($userAgentsMapping[$n['userid']])) {
        $person = $writer->agentOid($userAgentsMapping[$n['userid']]);
    } else {
        $person = $writer->userOid($n['userid']);
    }

    $ticket = [
        'ref'          => $n['ticketmaskid'],
        'subject'      => $n['subject'] ?: 'No subject',
        'person'       => $person,
        'agent'        => $writer->agentOid($n['staffid']),
        'department'   => isset($ticketDepartmentMapping[$n['departmentid']]) ? $ticketDepartmentMapping[$n['departmentid']] : $n['departmenttitle'],
        'status'       => isset($statusMapping[$n['ticketstatustitle']]) ? $statusMapping[$n['ticketstatustitle']] : 'awaiting_agent',
        'date_created' => date('c', $n['dateline']),
    ];

    // dp doesn't have 'on_hold' status but has is_hold flag
    if ($ticket['status'] === 'on_hold') {
        $ticket['status']  = 'awaiting_agent';
        $ticket['is_hold'] = true;
    }

    // get ticket messages
    $messagePager = $db->getPager('SELECT * FROM swticketposts WHERE ticketid = :ticket_id', [
        'ticket_id' => $n['ticketid'],
    ]);

    foreach ($messagePager as $m) {
        if (!$m['contents']) {
            $m['contents'] = 'empty content';
        }

        // multiline formatting
        $m['contents'] = str_replace("\n", '<br/>', $m['contents']);

        $person = null;
        if ($m['userid']) {
            if (isset($userAgentsMapping[$m['userid']])) {
                $person = $writer->agentOid($userAgentsMapping[$m['userid']]);
            } else {
                $person = $writer->userOid($m['userid']);
            }
        } elseif ($m['staffid']) {
            $person = $writer->agentOid($m['staffid']);
        } elseif ($m['email']) {
            $person = $m['email'];
        } else {
            $person = 'imported.message.' . $m['ticketpostid'] . '@example.com';
        }

        $message = [
            'oid'          => 'post_' . $m['ticketpostid'],
            'person'       => $person,
            'message'      => $m['contents'],
            'date_created' => date('c', $m['dateline']),
            'attachments'  => [],
        ];

        // get message attachments
        $attachments = $db->findAll('SELECT * FROM swattachments WHERE ticketid = :ticket_id AND linktypeid = :message_id', [
            'ticket_id'  => $n['ticketid'],
            'message_id' => $m['ticketpostid'],
        ]);

        foreach ($attachments as $a) {
            $attachment = [
                'oid'          => $a['attachmentid'],
                'file_name'    => $a['filename'] ?: ('attachment'.$a['attachmentid']),
                'content_type' => $a['filetype'],
                'blob_data'    => '',
            ];

            $attachmentChunks = $db->findAll('SELECT * FROM swattachmentchunks WHERE attachmentid = :attachment_id', [
                'attachment_id' => $a['attachmentid'],
            ]);

            foreach ($attachmentChunks as $c) {
                if ($c['notbase64']) {
                    $attachment['blob_data'] .= $c['contents'];
                } else {
                    // seems that's not used, skip for now
                }
            }

            if (!$attachment['blob_data']) {
                // skip attachments w/o content
                continue;
            } else {
                $attachment['blob_data'] = base64_encode($attachment['blob_data']);
            }

            $message['attachments'][] = $attachment;
        }

        $ticket['messages'][] = $message;
    }

    // get ticket notes
    $notesPager = $db->getPager('SELECT * FROM swticketnotes WHERE linktype = 1 AND linktypeid = :ticket_id', [
        'ticket_id' => $n['ticketid'],
    ]);

    foreach ($notesPager as $m) {
        if (!$m['staffid']) {
            continue;
        }
        if (!$m['note']) {
            $m['note'] = 'empty content';
        }

        $ticket['messages'][] = [
            'oid'          => 'note_' . $m['ticketnoteid'],
            'person'       => $writer->agentOid($m['staffid']),
            'message'      => $m['note'],
            'is_note'      => true,
            'date_created' => date('c', $m['dateline']),
        ];
    }

    if (!$ticket['person']) {
        // person is a mandatory field
        // if it's not set on the ticket then try to get it from the first message
        if (isset($ticket['messages'][0]['person'])) {
            $ticket['person'] = $ticket['messages'][0]['person'];
        }

        // otherwise generate a fake one to prevent validation errors
        if (!$ticket['person']) {
            $ticket['person'] = 'imported.ticket.user.'.$n['ticketid'].'@example.com';
        }
    }

    $writer->writeTicket($n['ticketid'], $ticket);
}

//--------------------
// Article categories
//--------------------

$output->startSection('Article categories');
$getArticleCategories = function ($parentId) use ($db, &$getArticleCategories) {
    $pager = $db->getPager('SELECT * FROM swkbcategories WHERE parentkbcategoryid = :parent_id', [
        'parent_id' => $parentId,
    ]);

    $categories = [];
    foreach ($pager as $n) {
        $categories[] = [
            'oid'        => $n['kbcategoryid'],
            'title'      => $n['title'],
            'categories' => $getArticleCategories($n['kbcategoryid']),
        ];
    }

    return $categories;
};

foreach ($getArticleCategories(0) as $category) {
    $writer->writeArticleCategory($category['oid'], $category);
}

//--------------------
// Articles
//--------------------

$output->startSection('Articles');
// todo need status mapping
$statusMapping = [
    1 => 'published',
];

$pager = $db->getPager('SELECT * FROM swkbarticles');
foreach ($pager as $n) {
    // todo need to confirm that it's correct fetching
    $categories = $db->findAll('SELECT * FROM swkbarticlelinks WHERE kbarticleid = :article_id AND linktypeid > 0', [
        'article_id' => $n['kbarticleid']
    ]);
    $categories = array_map(function($category) {
        return $category['linktypeid'];
    }, $categories);

    $article = [
        'title'        => $n['subject'],
        'person'       => $writer->agentOid($n['creatorid']),
        'content'      => '',
        'status'       => isset($statusMapping[ $n['articlestatus'] ]) ? $statusMapping[ $n['articlestatus'] ] : 'published',
        'categories'   => $categories,
        'date_created' => date('c', $n['dateline']),
    ];

    // get article content
    $contentPager = $db->getPager('SELECT * FROM swkbarticledata WHERE kbarticleid = :article_id', [
        'article_id' => $n['kbarticleid'],
    ]);

    foreach ($contentPager as $c) {
        $article['content'] .= $c['contents'];
    }

    if (!$article['content']) {
        $article['content'] = 'no content';
    }

    $writer->writeArticle($n['kbarticleid'], $article);
}

//--------------------
// News
//--------------------

$output->startSection('News');

$newsCategories = [];
$pager = $db->getPager('SELECT * FROM swnewscategories');
foreach ($pager as $n) {
    $newsCategories[$n['newscategoryid']] = $n['categorytitle'];
}

// todo need status mapping
$statusMapping = [
    2 => 'published',
];

$pager = $db->getPager('SELECT * FROM swnewsitems');
foreach ($pager as $n) {
    $news = [
        'title'        => $n['subject'],
        'person'       => $writer->agentOid($n['staffid']),
        'content'      => '',
        'status'       => isset($statusMapping[ $n['newsstatus'] ]) ? $statusMapping[ $n['newsstatus'] ] : 'published',
        'date_created' => date('c', $n['dateline']),
    ];

    // get news content
    $contentPager = $db->getPager('SELECT * FROM swnewsitemdata WHERE newsitemid = :news_id', [
        'news_id' => $n['newsitemid'],
    ]);

    foreach ($contentPager as $c) {
        $news['content'] .= $c['contents'];
    }

    if (!$news['content']) {
        $news['content'] = 'no content';
    }

    // get news category
    $category = $db->findOne('SELECT * FROM swnewscategorylinks WHERE newsitemid = :news_id', [
        'news_id' => $n['newsitemid'],
    ]);

    if ($category && isset($newsCategories[$category['newscategoryid']])) {
        $news['category'] = $newsCategories[$category['newscategoryid']];
    }

    $writer->writeNews($n['newsitemid'], $news);
}

//--------------------
// Chats
//--------------------

$output->startSection('Chats');

if ($db->tableExists('swchatobjects')) {
    $pager = $db->getPager('SELECT * FROM swchatobjects');
    foreach ($pager as $n) {
        $chat = [
            'subject'      => $n['subject'],
            'person'       => $n['userid'] ? $writer->userOid($n['userid']) : $n['useremail'],
            'agent'        => $writer->agentOid($n['staffid']),
            'date_created' => date('c', $n['dateline']),
            'date_ended'   => $n['lastpostactivity'] ? date('c', $n['lastpostactivity']) : date('c', $n['dateline']),
            'ended_by'     => 'user',
            'messages'     => [],
        ];

        $participantNameMapping = [
            $n['userfullname'] => $chat['person'],
            $n['staffname']    => $chat['agent'],
        ];

        $chatData = $db->findOne('SELECT * FROM swchatdata WHERE chatobjectid = :chat_id', [
            'chat_id' => $n['chatobjectid'],
        ]);

        $chatMessages = unserialize($chatData['contents']);
        foreach ($chatMessages as $messageId => $m) {
            if ($m['actiontype'] !== 'message') {
                continue;
            }

            $chat['messages'][] = [
                'oid'          => $n['chatobjectid'] . '.' . $messageId,
                'person'       => isset($participantNameMapping[ $m['name'] ]) ? $participantNameMapping[ $m['name'] ] : null,
                'content'      => $m['base64'] ? base64_decode($m['message']) : $m['message'],
                'date_created' => date('c', $n['dateline']),
            ];
        }

        // skip empty chats
        // don't save chat if no messages
        if (!$chat['messages']) {
            continue;
        }

        $writer->writeChat($n['chatobjectid'], $chat);
    }
}

//--------------------
// Settings
//--------------------

$output->startSection('Settings');
$settingMapping = [
    'general_producturl'  => 'core.deskpro_url',
    'general_companyname' => 'core.deskpro_name',
];

$pager = $db->getPager('SELECT * FROM swsettings WHERE section = :section AND vkey IN (:setting_names)', [
    'section'       => 'settings',
    'setting_names' => array_keys($settingMapping),
]);

foreach ($pager as $n) {
    $writer->writeSetting($n['settingid'], [
        'name'  => $settingMapping[$n['vkey']],
        'value' => $n['data'],
    ]);
}
