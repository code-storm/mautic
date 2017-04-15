<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
if ($tmpl == 'index') {
    $view->extend('MauticLeadBundle:Lead:index.html.php');
}

$customButtons = [];
if ($permissions['lead:leads:editown'] || $permissions['lead:leads:editother']) {
    $customButtons = [
        [
            'attr' => [
                'class'       => 'btn btn-default btn-sm btn-nospin',
                'data-toggle' => 'ajaxmodal',
                'data-target' => '#MauticSharedModal',
                'href'        => $view['router']->path('mautic_contact_action', ['objectAction' => 'batchLists']),
                'data-header' => $view['translator']->trans('mautic.lead.batch.lists'),
            ],
            'btnText'   => $view['translator']->trans('mautic.lead.batch.lists'),
            'iconClass' => 'fa fa-pie-chart',
        ],
        [
            'attr' => [
                'class'       => 'btn btn-default btn-sm btn-nospin',
                'data-toggle' => 'ajaxmodal',
                'data-target' => '#MauticSharedModal',
                'href'        => $view['router']->path('mautic_contact_action', ['objectAction' => 'batchStages']),
                'data-header' => $view['translator']->trans('mautic.lead.batch.stages'),
            ],
            'btnText'   => $view['translator']->trans('mautic.lead.batch.stages'),
            'iconClass' => 'fa fa-tachometer',
        ],
        [
            'attr' => [
                'class'       => 'btn btn-default btn-sm btn-nospin',
                'data-toggle' => 'ajaxmodal',
                'data-target' => '#MauticSharedModal',
                'href'        => $view['router']->path('mautic_contact_action', ['objectAction' => 'batchCampaigns']),
                'data-header' => $view['translator']->trans('mautic.lead.batch.campaigns'),
            ],
            'btnText'   => $view['translator']->trans('mautic.lead.batch.campaigns'),
            'iconClass' => 'fa fa-clock-o',
        ],
        [
            'attr' => [
                'class'       => 'hidden-xs btn btn-default btn-sm btn-nospin',
                'data-toggle' => 'ajaxmodal',
                'data-target' => '#MauticSharedModal',
                'href'        => $view['router']->path('mautic_contact_action', ['objectAction' => 'batchDnc']),
                'data-header' => $view['translator']->trans('mautic.lead.batch.dnc'),
            ],
            'btnText'   => $view['translator']->trans('mautic.lead.batch.dnc'),
            'iconClass' => 'fa fa-ban text-danger',
        ],
    ];
}
?>
<?php $session = $this->get('session'); ?>
<?php if (count($items)):
    if($session->get('mautic.lead.filter') != ''){
?>
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
$conn = mysqli_connect("localhost", "root", "smallworld", "mautic");
if (!$conn) {
die("Connection failed: " . mysqli_connect_error());
}

// $query ="SELECT *, (SELECT GROUP_CONCAT(ipaddr SEPARATOR ';') FROM leads l2 WHERE l2.mtcookie = l1.mtcookie) As ip_all FROM leads l1 GROUP BY l1.mtcookie order by l1.last_active desc";

$query = "SELECT l1.id, l1.date_modified, l1.mtcookie,l1.smtcookie, l1.ipaddr,l1.email, ph.lead_id,i1.ip_address,ph.city,ph.region,ph.country, l1.last_active, (SELECT GROUP_CONCAT(DISTINCT(i2.ip_address) SEPARATOR ';') FROM `leads` l2 left JOIN page_hits ph2 ON l2.id = ph2.lead_id left JOIN ip_addresses i2 on i2.id=ph2.ip_id where l2.mtcookie = l1.mtcookie) AS ip_all FROM `leads` l1 left JOIN page_hits ph ON l1.id = ph.lead_id left JOIN ip_addresses i1 on i1.id=ph.ip_id where smtcookie is not null GROUP BY l1.smtcookie order by l1.last_active desc";

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
<?php }
else
{
?>
<div class="table-responsive">

    <table class="table table-hover table-striped table-bordered" id="leadTable">
        <thead>
            <tr>
                <?php
                echo $view->render('MauticCoreBundle:Helper:tableheader.html.php', [
                    'checkall'        => 'true',
                    'target'          => '#leadTable',
                    'templateButtons' => [
                        'delete' => $permissions['lead:leads:deleteown'] || $permissions['lead:leads:deleteother'],
                    ],
                    'customButtons' => $customButtons,
                    'langVar'       => 'lead.lead',
                    'routeBase'     => 'contact',
                ]);

                echo $view->render('MauticCoreBundle:Helper:tableheader.html.php', [
                    'sessionVar' => 'lead',
                    'orderBy'    => 'l.lastname, l.firstname, l.company, l.email',
                    'text'       => 'mautic.core.name',
                    'class'      => 'col-lead-name',
                ]);

                echo $view->render('MauticCoreBundle:Helper:tableheader.html.php', [
                    'sessionVar' => 'lead',
                    'orderBy'    => 'l.email',
                    'text'       => 'mautic.core.type.email',
                    'class'      => 'col-lead-email visible-md visible-lg',
                ]);

                echo $view->render('MauticCoreBundle:Helper:tableheader.html.php', [
                    'sessionVar' => 'lead',
                    'orderBy'    => 'l.city, l.state',
                    'text'       => 'mautic.lead.lead.thead.location',
                    'class'      => 'col-lead-location visible-md visible-lg',
                ]);
                echo $view->render('MauticCoreBundle:Helper:tableheader.html.php', [
                    'sessionVar' => 'lead',
                    'orderBy'    => 'l.stage_id',
                    'text'       => 'mautic.lead.stage.label',
                    'class'      => 'col-lead-stage',
                ]);
                echo $view->render('MauticCoreBundle:Helper:tableheader.html.php', [
                    'sessionVar' => 'lead',
                    'orderBy'    => 'l.points',
                    'text'       => 'mautic.lead.points',
                    'class'      => 'visible-md visible-lg col-lead-points',
                ]);

                echo $view->render('MauticCoreBundle:Helper:tableheader.html.php', [
                    'sessionVar' => 'lead',
                    'orderBy'    => 'l.last_active',
                    'text'       => 'mautic.lead.lastactive',
                    'class'      => 'col-lead-lastactive visible-md visible-lg',
                    'default'    => true,
                ]);

                echo $view->render('MauticCoreBundle:Helper:tableheader.html.php', [
                    'sessionVar' => 'lead',
                    'orderBy'    => 'l.id',
                    'text'       => 'mautic.core.id',
                    'class'      => 'col-lead-id visible-md visible-lg',
                ]);
                ?>
            </tr>
        </thead>
        <tbody>
        <?php echo $view->render('MauticLeadBundle:Lead:list_rows.html.php', [
            'items'         => $items,
            'security'      => $security,
            'currentList'   => $currentList,
            'permissions'   => $permissions,
            'noContactList' => $noContactList,
        ]); ?>
        </tbody>
    </table>
</div>
<div class="panel-footer hide">
    <?php echo $view->render('MauticCoreBundle:Helper:pagination.html.php', [
        'totalItems' => $totalItems,
        'page'       => $page,
        'limit'      => $limit,
        'menuLinkId' => 'mautic_contact_index',
        'baseUrl'    => $view['router']->path('mautic_contact_index'),
        'tmpl'       => $indexMode,
        'sessionVar' => 'lead',
    ]); ?>
</div>
<?php } ?>
<?php else: ?>
<?php echo $view->render('MauticCoreBundle:Helper:noresults.html.php'); ?>
<?php endif; ?>
