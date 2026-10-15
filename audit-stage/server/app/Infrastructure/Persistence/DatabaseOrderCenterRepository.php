<?php
// Current dev/2.5.0 keyword boundary excerpt used by regression evidence.
$where = "o.user_id=:center_user_id AND o.lifecycle_status<>'archived'";
$keyword = trim($keyword ?? '');
if ($kind === 'mine' && $keyword !== '') {
    $where .= " AND (
        o.public_no LIKE :center_keyword_no
        OR CONCAT_WS(' ',COALESCE(o.title,''),COALESCE(o.category_names_snapshot,''),COALESCE(o.category_name_snapshot,''),COALESCE(o.district,''),COALESCE(o.address_summary,'')) LIKE :center_keyword_text
    )";
}