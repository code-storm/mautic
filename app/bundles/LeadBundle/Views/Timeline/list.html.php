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
        <tbody>
          <?php
          if(empty($events['events'][0]['extra']["hit"]['query']['mtcookie']))
          {

            $cookieid= $events['events'][1]['extra']["hit"]['query']['mtcookie'];

          }
          else {

            $cookieid= $events['events'][0]['extra']["hit"]['query']['mtcookie'];
          }
          $conn = mysqli_connect("localhost", "root", "", "mautic");
          if (!$conn) {
          die("Connection failed: " . mysqli_connect_error());
          }

          // $query ="SELECT *, (SELECT GROUP_CONCAT(ipaddr SEPARATOR ';') FROM leads l2 WHERE l2.mtcookie = l1.mtcookie) As ip_all FROM leads l1 GROUP BY l1.mtcookie order by l1.last_active desc";

          $query = "SELECT * FROM `page_hits` ph left join leads l on ph.lead_id = l.id where l.mtcookie='$cookieid'";

                 $result = mysqli_query($conn,$query);


                 if (mysqli_num_rows($result) > 0){
                   echo "inside";
                   while ($row = mysqli_fetch_assoc($result)){

          echo '<tr><td></td><td>'.$row["url"].'</td>';
          echo '<td>'.$row["url_title"].'</td>';
          echo "<td></td>"; ?>
          <td class="timeline-timestamp"><?php  echo $view['date']->toText($row['date_hit'], 'local', 'Y-m-d H:i:s', true);  ?></td>
          <?php echo "</tr>"; } }?>


        </tbody>
    </table>
</div>
<?php echo $view->render(
    'MauticCoreBundle:Helper:pagination.html.php',
    [
        'page'       => $events['page'],
        'fixedPages' => $events['maxPages'],
        'fixedLimit' => true,
        'baseUrl'    => $baseUrl,
        'target'     => '#timeline-table',
        'totalItems' => $events['total'],
    ]
); ?>

<!--/ timeline -->
