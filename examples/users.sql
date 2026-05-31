SELECT
    u.id,
    u.name,
    COUNT(o.id) AS order_count
FROM
    users u
LEFT JOIN
    orders o
    ON u.id = o.user_id
WHERE
    u.active = 1
    AND u.created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)
GROUP BY
    u.id
HAVING
    COUNT(o.id) > 0
ORDER BY
    u.name
