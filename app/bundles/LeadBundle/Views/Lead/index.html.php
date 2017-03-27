<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
$view->extend('MauticCoreBundle:Default:content.html.php');
$view['slots']->set('mauticContent', 'lead');
$view['slots']->set('headerTitle', $view['translator']->trans('mautic.lead.leads'));

$pageButtons = [];
if ($permissions['lead:leads:create']) {
    $pageButtons[] = [
        'attr' => [
            'class'       => 'btn btn-default btn-nospin quickadd',
            'data-toggle' => 'ajaxmodal',
            'data-target' => '#MauticSharedModal',
            'href'        => $view['router']->path('mautic_contact_action', ['objectAction' => 'quickAdd']),
            'data-header' => $view['translator']->trans('mautic.lead.lead.menu.quickadd'),
        ],
        'iconClass' => 'fa fa-bolt',
        'btnText'   => 'mautic.lead.lead.menu.quickadd',
        'primary'   => true,
    ];

    $pageButtons[] = [
        'attr' => [
            'href' => $view['router']->path('mautic_contact_action', ['objectAction' => 'import']),
        ],
        'iconClass' => 'fa fa-upload',
        'btnText'   => 'mautic.lead.lead.import',
    ];
}

// Only show toggle buttons for accessibility
$extraHtml = <<<button
<div class="btn-group ml-5 sr-only ">
    <span data-toggle="tooltip" title="{$view['translator']->trans(
    'mautic.lead.tooltip.list'
)}" data-placement="left"><a id="table-view" href="{$view['router']->path('mautic_contact_index', ['page' => $page, 'view' => 'list'])}" data-toggle="ajax" class="btn btn-default"><i class="fa fa-fw fa-table"></i></span></a>
    <span data-toggle="tooltip" title="{$view['translator']->trans(
    'mautic.lead.tooltip.grid'
)}" data-placement="left"><a id="card-view" href="{$view['router']->path('mautic_contact_index', ['page' => $page, 'view' => 'grid'])}" data-toggle="ajax" class="btn btn-default"><i class="fa fa-fw fa-th-large"></i></span></a>
</div>
button;

$view['slots']->set(
    'actions',
    $view->render(
        'MauticCoreBundle:Helper:page_actions.html.php',
        [
            'templateButtons' => [
                'new' => $permissions['lead:leads:create'],
            ],
            'routeBase'     => 'contact',
            'langVar'       => 'lead.lead',
            'customButtons' => $pageButtons,
            'extraHtml'     => $extraHtml,
        ]
    )
);

$toolbarButtons = [
    [
        'attr' => [
            'class'       => 'hidden-xs btn btn-default btn-sm btn-nospin',
            'href'        => 'javascript: void(0)',
            'onclick'     => 'Mautic.toggleLiveLeadListUpdate();',
            'id'          => 'liveModeButton',
            'data-toggle' => false,
            'data-max-id' => $maxLeadId,
        ],
        'tooltip'   => $view['translator']->trans('mautic.lead.lead.live_update'),
        'iconClass' => 'fa fa-bolt',
    ],
];

if ($indexMode == 'list') {
    $toolbarButtons[] = [
        'attr' => [
            'class'          => 'hidden-xs btn btn-default btn-sm btn-nospin'.(($anonymousShowing) ? ' btn-primary' : ''),
            'href'           => 'javascript: void(0)',
            'onclick'        => 'Mautic.toggleAnonymousLeads();',
            'id'             => 'anonymousLeadButton',
            'data-anonymous' => $view['translator']->trans('mautic.lead.lead.searchcommand.isanonymous'),
        ],
        'tooltip'   => $view['translator']->trans('mautic.lead.lead.anonymous_leads'),
        'iconClass' => 'fa fa-user-secret',
    ];
}
?>

<div class="panel panel-default bdr-t-wdh-0 mb-0">
    <?php echo $view->render(
        'MauticCoreBundle:Helper:list_toolbar.html.php',
        [
            'searchValue'   => $searchValue,
            'searchHelp'    => 'mautic.lead.lead.help.searchcommands',
            'action'        => $currentRoute,
            'customButtons' => $toolbarButtons,
        ]
    ); ?>
    <div class="page-list">
        <?php $view['slots']->output('_content'); ?>
    </div>
</div>
<table class="table table-hover table-striped table-bordered" id="leadTable2">
    <thead>
    <tr>
        <th >cookie id</th>
        <th >email</th>
        <th>Ip Address</th>
        <th> Country</th>
<th> Last active</th>
<th> Id</th>
        </tr>
        <tbody>
<?php
$conn = mysqli_connect("localhost", "root", "", "mautic");
if (!$conn) {
die("Connection failed: " . mysqli_connect_error());
}

// $query ="SELECT *, (SELECT GROUP_CONCAT(ipaddr SEPARATOR ';') FROM leads l2 WHERE l2.mtcookie = l1.mtcookie) As ip_all FROM leads l1 GROUP BY l1.mtcookie order by l1.last_active desc";

$query = "SELECT l1.id, l1.date_modified, l1.mtcookie, l1.ipaddr,l1.email, ph.lead_id,i1.ip_address,ph.city,ph.region,ph.country, l1.last_active, (SELECT GROUP_CONCAT(DISTINCT(i2.ip_address) SEPARATOR ';') FROM `leads` l2 left JOIN page_hits ph2 ON l2.id = ph2.lead_id left JOIN ip_addresses i2 on i2.id=ph2.ip_id where l2.mtcookie = l1.mtcookie) AS ip_all FROM `leads` l1 left JOIN page_hits ph ON l1.id = ph.lead_id left JOIN ip_addresses i1 on i1.id=ph.ip_id where mtcookie is not null GROUP BY l1.mtcookie order by l1.last_active desc";

       $result = mysqli_query($conn,$query);
       if (mysqli_num_rows($result) > 0){
         while ($row = mysqli_fetch_assoc($result)){
     echo "<tr>";
     echo "<td>";
     echo $row['mtcookie'];
     echo "</td>";
     echo "<td>";
     echo $row['email'];
     echo "</td>";
    
     echo "<td> <a href='/mautic/index.php/s/contacts/view/".$row['id']."' data-toggle='ajax'>
                                               <div>".$row['ip_all']."</div>
                       <div class='small'></div>
                   </a>";
     echo "</td>";
     echo "<td>";
   $flag =(!empty($row['country'])) ? $view['assets']->getCountryFlag($row['country']) : '';?>

   <img src="<?php echo $flag; ?>" alt="<?php echo $row['country'] ?>" style="max-height: 24px;" class="mr-sm" />

<?php
echo (!empty($row['city'])) ? $row['city'].", " : '';
echo (!empty($row['state'])) ? $row['state'] : '';
echo "</td><td>";
echo $row['last_active'];
echo "</td><td>";
echo $row['id'];
echo "</td>";

     echo "</tr>";

     }}


?>

 </tbody>
</table>
