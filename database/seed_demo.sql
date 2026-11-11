USE idtctw_anhouse;

INSERT INTO properties (org_id, title, city, district, rent, status)
SELECT o.id, '快樂街 12 號 302 房', '台北市', '大安區', 15800, 'rented'
FROM organizations o
WHERE o.type = 'personal' AND o.owner_user_id = (SELECT id FROM users WHERE phone = '0932302648' LIMIT 1)
LIMIT 1;

INSERT INTO properties (org_id, title, city, district, rent, status)
SELECT o.id, '幸福路 8 號', '台北市', '信義區', 22000, 'rented'
FROM organizations o
WHERE o.type = 'personal' AND o.owner_user_id = (SELECT id FROM users WHERE phone = '0932302648' LIMIT 1)
LIMIT 1;

INSERT INTO properties (org_id, title, city, district, rent, status)
SELECT o.id, '安康巷 5 號', '台北市', '大安區', 12000, 'vacant'
FROM organizations o
WHERE o.type = 'personal' AND o.owner_user_id = (SELECT id FROM users WHERE phone = '0932302648' LIMIT 1)
LIMIT 1;

INSERT INTO bills (property_id, period, amount, due_date, status)
SELECT id, '2026-11', 15800, '2026-11-15', 'pending' FROM properties WHERE title = '快樂街 12 號 302 房' LIMIT 1;

INSERT INTO bills (property_id, period, amount, due_date, status)
SELECT id, '2026-11', 22000, '2026-11-15', 'paid' FROM properties WHERE title = '幸福路 8 號' LIMIT 1;

UPDATE bills SET paid_at = '2026-11-08 10:30:00' WHERE period = '2026-11' AND status = 'paid';

INSERT INTO bills (property_id, period, amount, due_date, status)
SELECT id, '2026-10', 15800, '2026-10-15', 'pending' FROM properties WHERE title = '快樂街 12 號 302 房' LIMIT 1;
