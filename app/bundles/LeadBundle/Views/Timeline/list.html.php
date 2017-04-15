<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
if (isset($tmpl) && $tmpl == 'index') {
    $view->extend('MauticLeadBundle:Timeline:index.html.php');
}

$baseUrl = $view['router']->path(
    'mautic_contacttimeline_action',
    [
        'leadId' => $lead->getId(),
    ]
);
?>
<style>
  tr.table-info
  {
    background-color: #e8e8e8;
  }
</style>
<!-- timeline -->
<div class="table-responsive">
    <table class="table table-hover table-bordered" id="contact-timeline">
        <thead>
        <tr>
            <th class="timeline-icon">
                <a class="btn btn-sm btn-nospin btn-default" data-activate-details="all" data-toggle="tooltip" title="<?php echo $view['translator']->trans(
                    'mautic.lead.timeline.toggle_all_details'
                ); ?>">
                    <span class="fa fa-fw fa-level-down"></span>
                </a>
            </th>
            <?php

            echo $view->render('MauticCoreBundle:Helper:tableheader.html.php', [
                'orderBy'    => 'eventLabel',
                'text'       => 'mautic.lead.timeline.event_name',
                'class'      => 'timeline-name',
                'sessionVar' => 'lead.'.$lead->getId().'.timeline',
                'baseUrl'    => $baseUrl,
                'target'     => '#timeline-table',
            ]);

            echo $view->render('MauticCoreBundle:Helper:tableheader.html.php', [
                'orderBy'    => 'eventType',
                'text'       => 'mautic.lead.timeline.event_type',
                'class'      => 'visible-md visible-lg timeline-type',
                'sessionVar' => 'lead.'.$lead->getId().'.timeline',
                'baseUrl'    => $baseUrl,
                'target'     => '#timeline-table',
            ]);
            //added by nitin for hit type
            echo $view->render('MauticCoreBundle:Helper:tableheader.html.php', [
                'orderBy'    => 'hitType',
                'text'       => 'mautic.lead.timeline.hit_type',
                'class'      => 'visible-md visible-lg timeline-type',
                'sessionVar' => 'lead.'.$lead->getId().'.timeline',
                'baseUrl'    => $baseUrl,
                'target'     => '#timeline-table',
            ]);

            echo $view->render('MauticCoreBundle:Helper:tableheader.html.php', [
                'orderBy'    => 'timestamp',
                'text'       => 'mautic.lead.timeline.event_timestamp',
                'class'      => 'visible-md visible-lg timeline-timestamp',
                'sessionVar' => 'lead.'.$lead->getId().'.timeline',
                'baseUrl'    => $baseUrl,
                'target'     => '#timeline-table',
            ]);
            ?>
        </tr>
        </thead>
        <tbody>
          <?php
          if(empty($events['events'][0]['extra']["hit"]['query']['mtcookie']))
          {

            $cookieid= $events['events'][1]['extra']["hit"]['query']['mtcookie'];
            $sessioncookieid= $events['events'][1]['extra']["hit"]['query']['smtcookie'];

          }
          else {

            $cookieid= $events['events'][0]['extra']["hit"]['query']['mtcookie'];
            $sessioncookieid= $events['events'][0]['extra']["hit"]['query']['smtcookie'];

          }
          $conn = mysqli_connect("localhost", "root", "smallworld", "mautic");
          if (!$conn) {
          die("Connection failed: " . mysqli_connect_error());
          }

          // $query ="SELECT *, (SELECT GROUP_CONCAT(ipaddr SEPARATOR ';') FROM leads l2 WHERE l2.mtcookie = l1.mtcookie) As ip_all FROM leads l1 GROUP BY l1.mtcookie order by l1.last_active desc";
          $emailuser = $events['events'][0]['extra']["hit"]['query']['email'];
          if($emailuser!='')
          {
          $query = "SELECT *,(select i.ip_address from ip_addresses i where i.id = ph.ip_id) as ipaddr FROM page_hits ph LEFT JOIN leads l ON ph.lead_id = l.id WHERE l.email = '$emailuser' ORDER BY ph.ip_id, ph.date_hit DESC ";
        }
        else
        {
          echo $query = "SELECT *,(select i.ip_address from ip_addresses i where i.id = ph.ip_id) as ipaddr FROM page_hits ph LEFT JOIN leads l ON ph.lead_id = l.id WHERE l.smtcookie = '$sessioncookieid' ORDER BY ph.ip_id, ph.date_hit DESC ";
        }
                 $result = mysqli_query($conn,$query);


                 if (mysqli_num_rows($result) > 0){
                   $_ip;
                   $_var_pagehit = 'Page hit';
                   while ($row = mysqli_fetch_assoc($result)){
                   if(!isset($_ip))
                    {
                      $_ip = $row["ip_id"];
                      $_var_pagehit = 'Lead Created';
                      echo "<tr class='table-info'>
                      <th scope='row'>IP</th> <th>".$row["ipaddr"]."</th> <td></td> <td></td><td></td> </tr>
                      <tr><td></td><td>".$row["url"]."</td><td>".$row["url_title"]."</td><td>".$_var_pagehit."</td><td class='timeline-timestamp'>".$view['date']->toText($row['date_hit'], 'local', 'Y-m-d H:i:s', true)."</td></tr>";
                      $_var_pagehit = 'Lead Identified';
                    }
                  if($_ip != $row["ip_id"])
                    {
                      $_ip = $row["ip_id"];
                      $_var_pagehit = 'Lead Created';
                      echo "<tr class='table-info'>
                      <th scope='row'>IP</th> <th>".$row["ipaddr"]."</th> <td></td> <td></td><td></td> </tr>
                      <tr><td></td><td>".$row["url"]."</td><td>".$row["url_title"]."</td><td>".$_var_pagehit."</td><td class='timeline-timestamp'>".$view['date']->toText($row['date_hit'], 'local', 'Y-m-d H:i:s', true)."</td></tr>";
                      $_var_pagehit = 'Lead Identified';
                    }
                    else
                      $_var_pagehit = 'Page hit';

          echo '<tr><td></td><td>'.$row["url"].'</td>';
          echo '<td>'.$row["url_title"].'</td>';
          echo "<td>".$_var_pagehit."</td>"; ?>
          <td class="timeline-timestamp"><?php  echo $view['date']->toText($row['date_hit'], 'local', 'Y-m-d H:i:s', true);  ?></td>
          <?php echo "</tr>"; } }?>


        </tbody>
    </table>
</div>
<?php
echo $view->render(
    'MauticCoreBundle:Helper:pagination.html.php',
    [
        'page'       => $events['page'],
        'fixedPages' => $events['maxPages'],
        'fixedLimit' => true,
        'baseUrl'    => $baseUrl,
        'target'     => '#timeline-table',
        'totalItems' => mysqli_num_rows($result) -1,
    ]
); ?>

<!--/ timeline -->
