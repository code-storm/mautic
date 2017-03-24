### Mautic to get all ips
SELECT l1.id, l1.date_modified, l1.mtcookie, l1.ipaddr, ph.lead_id,i1.ip_address,ph.city,ph.region,ph.country,
(SELECT GROUP_CONCAT(DISTINCT(i2.ip_address) SEPARATOR ';') FROM `leads` l2 
left JOIN page_hits ph2 ON l2.id = ph2.lead_id
left JOIN ip_addresses i2 on i2.id=ph2.ip_id 
where l2.mtcookie = l1.mtcookie) AS ip_all FROM `leads` l1
left JOIN page_hits ph ON l1.id = ph.lead_id 
left JOIN ip_addresses i1 on i1.id=ph.ip_id where mtcookie is not null GROUP BY l1.mtcookie