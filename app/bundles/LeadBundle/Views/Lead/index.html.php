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

 <table class="table" id="contact-timeline">
     <thead>
     <tr>
         <th >user id</th>
         <th >usep ip</th>
         </tr>
         <tbody>
<?php
 $conn = mysqli_connect("localhost", "root", "smallworld", "matic");
 if (!$conn) {
die("Connection failed: " . mysqli_connect_error());
}

$query ="SELECT *, (SELECT GROUP_CONCAT(ipaddr SEPARATOR ';') FROM leads l2 WHERE l2.mtcookie = l1.mtcookie) As ip_all FROM leads l1 GROUP BY l1.mtcookie";

        $result = mysqli_query($conn,$query);
        if (mysqli_num_rows($result) > 0){
          while ($row = mysqli_fetch_assoc($result)){
      echo "<tr>";
      echo "<td>";
      echo $row['mtcookie'];
      echo "</td>";
      echo "<td>";
      echo $row['ip_all'];
      echo "</td>";
      echo "<td>";
    $flag =(!empty($row['country'])) ? $view['assets']->getCountryFlag($row['country']) : '';?>

    <img src="<?php echo $flag; ?>" style="max-height: 24px;" class="mr-sm" />
<?php
      echo "</tr>";

      }}


?>

  </tbody>
</table>
