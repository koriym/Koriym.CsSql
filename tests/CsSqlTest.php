<?php

declare(strict_types=1);

namespace Koriym\CsSql;

use PHPUnit\Framework\TestCase;

use function str_repeat;

class CsSqlTest extends TestCase
{
    private CsSql $formatter;

    protected function setUp(): void
    {
        $this->formatter = new CsSql();
    }

    public function testSimpleSelect(): void
    {
        $sql = 'SELECT id, name FROM users';
        $expected = "SELECT\n    id,\n    name\nFROM\n    users";
        $this->assertEquals($expected, $this->formatter->format($sql));
    }

    public function testComplexSelect(): void
    {
        $sql = 'SELECT u.id, u.name, o.order_date, p.product_name FROM users u JOIN orders o ON u.id = o.user_id JOIN order_items oi ON o.id = oi.order_id JOIN products p ON oi.product_id = p.id WHERE u.age > 18 AND o.total > 100 GROUP BY u.id HAVING COUNT(o.id) > 5 ORDER BY o.order_date DESC LIMIT 10';
        $expected = "SELECT\n    u.id,\n    u.name,\n    o.order_date,\n    p.product_name\nFROM\n    users u\nJOIN\n    orders o\n    ON u.id = o.user_id\nJOIN\n    order_items oi\n    ON o.id = oi.order_id\nJOIN\n    products p\n    ON oi.product_id = p.id\nWHERE\n    u.age > 18\n    AND o.total > 100\nGROUP BY\n    u.id\nHAVING\n    COUNT(o.id) > 5\nORDER BY\n    o.order_date DESC\nLIMIT\n    10";
        $this->assertEquals($expected, $this->formatter->format($sql));
    }

    public function testNestedQueries(): void
    {
        $sql = 'SELECT id, name, (SELECT COUNT(*) FROM orders WHERE user_id = users.id) AS order_count, (SELECT SUM(total) FROM (SELECT total FROM orders WHERE user_id = users.id) AS user_orders) AS total_spent FROM users WHERE id IN (SELECT user_id FROM premium_subscriptions)';
        $expected = "SELECT\n    id,\n    name,\n    (\n        SELECT COUNT(*)\n        FROM\n            orders\n        WHERE\n            user_id = users.id\n    ) AS order_count,\n    (\n        SELECT SUM(total)\n        FROM\n            (\n                SELECT total\n                FROM\n                    orders\n                WHERE\n                    user_id = users.id\n            ) AS user_orders\n    ) AS total_spent\nFROM\n    users\nWHERE\n    id IN (\n        SELECT user_id\n        FROM\n            premium_subscriptions\n    )";
        $this->assertEquals($expected, $this->formatter->format($sql));
    }

    public function testInsertStatement(): void
    {
        $sql = "INSERT INTO users (name, email, created_at) VALUES ('John Doe', 'john@example.com', NOW()), ('Jane Doe', 'jane@example.com', NOW())";
        $expected = "INSERT INTO users (\n    name,\n    email,\n    created_at\n)\nVALUES (\n    'John Doe',\n    'john@example.com',\n    NOW()\n),\n(\n    'Jane Doe',\n    'jane@example.com',\n    NOW()\n)";
        $this->assertEquals($expected, $this->formatter->format($sql));
    }

    public function testUpdateStatement(): void
    {
        $sql = 'UPDATE users SET last_login = NOW(), login_count = login_count + 1 WHERE id = 1';
        $expected = "UPDATE users\nSET\n    last_login = NOW(),\n    login_count = login_count + 1\nWHERE\n    id = 1";
        $this->assertEquals($expected, $this->formatter->format($sql));
    }

    public function testNamedPlaceholders(): void
    {
        $sql = 'UPDATE users SET name = :name, email = : email WHERE id = :id AND type = :limit';
        $expected = "UPDATE users\nSET\n    name = :name,\n    email = :email\nWHERE\n    id = :id\n    AND type = :limit";
        $this->assertEquals($expected, $this->formatter->format($sql));
    }

    public function testDeleteStatement(): void
    {
        $sql = 'DELETE FROM users WHERE last_login < DATE_SUB(NOW(), INTERVAL 1 YEAR)';
        $expected = "DELETE FROM users\nWHERE\n    last_login < DATE_SUB(NOW(), INTERVAL 1 YEAR)";
        $this->assertEquals($expected, $this->formatter->format($sql));
    }

    public function testCreateTableStatement(): void
    {
        $sql = 'CREATE TABLE users (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(255) NOT NULL, email VARCHAR(255) UNIQUE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)';
        $expected = "CREATE TABLE users (\n    id INT PRIMARY KEY AUTO_INCREMENT,\n    name VARCHAR(255) NOT NULL,\n    email VARCHAR(255) UNIQUE,\n    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP\n)";
        $this->assertEquals($expected, $this->formatter->format($sql));
    }

    public function testAlterTableStatement(): void
    {
        $sql = 'ALTER TABLE users ADD COLUMN age INT, DROP COLUMN obsolete_column, MODIFY email VARCHAR(320), ADD INDEX idx_name (name)';
        $expected = "ALTER TABLE users\nADD COLUMN age INT,\nDROP COLUMN obsolete_column,\nMODIFY email VARCHAR(320),\nADD INDEX idx_name (name)";
        $this->assertEquals($expected, $this->formatter->format($sql));
    }

    public function testCaseStatement(): void
    {
        $sql = "SELECT id, name, CASE WHEN age < 18 THEN 'Minor' WHEN age BETWEEN 18 AND 65 THEN 'Adult' ELSE 'Senior' END AS age_group FROM users";
        $expected = "SELECT\n    id,\n    name,\n    CASE\n        WHEN age < 18 THEN 'Minor'\n        WHEN age BETWEEN 18 AND 65 THEN 'Adult'\n        ELSE 'Senior'\n    END AS age_group\nFROM\n    users";
        $this->assertEquals($expected, $this->formatter->format($sql));
    }

    public function testWithClause(): void
    {
        $sql = 'WITH RECURSIVE subordinates AS (SELECT id, manager_id, name FROM employees WHERE id = 1 UNION ALL SELECT e.id, e.manager_id, e.name FROM employees e INNER JOIN subordinates s ON s.id = e.manager_id) SELECT * FROM subordinates';
        $expected = "WITH RECURSIVE subordinates AS (\n    SELECT\n        id,\n        manager_id,\n        name\n    FROM\n        employees\n    WHERE\n        id = 1\n    UNION ALL\n    SELECT\n        e.id,\n        e.manager_id,\n        e.name\n    FROM\n        employees e\n    INNER JOIN\n        subordinates s\n        ON s.id = e.manager_id\n)\nSELECT *\nFROM\n    subordinates";
        $this->assertEquals($expected, $this->formatter->format($sql));
    }

    public function testUnionStatement(): void
    {
        $sql = "SELECT id, name, 'Employee' AS type FROM employees UNION ALL SELECT id, company_name, 'Company' AS type FROM companies ORDER BY name";
        $expected = "SELECT\n    id,\n    name,\n    'Employee' AS type\nFROM\n    employees\nUNION ALL\nSELECT\n    id,\n    company_name,\n    'Company' AS type\nFROM\n    companies\nORDER BY\n    name";
        $this->assertEquals($expected, $this->formatter->format($sql));
    }

    public function testComments(): void
    {
        $sql = "SELECT id, name -- This is an inline comment\n/* This is a\nmulti-line comment */ FROM users # Another comment style\nWHERE active = 1";
        $expected = "SELECT\n    id,\n    name -- This is an inline comment\n    /* This is a\nmulti-line comment */\nFROM\n    users # Another comment style\nWHERE\n    active = 1";
        $this->assertEquals($expected, $this->formatter->format($sql));
    }

    public function testQuotedIdentifiers(): void
    {
        $sql = "SELECT `id`, \"name\", [email] FROM `users` WHERE \"age\" > 18 AND [status] = 'active'";
        $expected = "SELECT\n    `id`,\n    \"name\",\n    [email]\nFROM\n    `users`\nWHERE\n    \"age\" > 18\n    AND [status] = 'active'";
        $this->assertEquals($expected, $this->formatter->format($sql));
    }

    public function testFunctions(): void
    {
        $sql = "SELECT id, CONCAT(first_name, ' ', last_name) AS full_name, ROUND(AVG(salary), 2) AS avg_salary FROM employees GROUP BY id";
        $expected = "SELECT\n    id,\n    CONCAT(first_name, ' ', last_name) AS full_name,\n    ROUND(AVG(salary), 2) AS avg_salary\nFROM\n    employees\nGROUP BY\n    id";
        $this->assertEquals($expected, $this->formatter->format($sql));
    }

    public function testSubqueries(): void
    {
        $sql = 'SELECT * FROM (SELECT id, name, ROW_NUMBER() OVER (ORDER BY name) AS row_num FROM users) AS numbered_users WHERE row_num BETWEEN 10 AND 20';
        $expected = "SELECT *\nFROM\n    (\n        SELECT\n            id,\n            name,\n            ROW_NUMBER() OVER (\n                ORDER BY name\n            ) AS row_num\n        FROM\n            users\n    ) AS numbered_users\nWHERE\n    row_num BETWEEN 10 AND 20";
        $this->assertEquals($expected, $this->formatter->format($sql));
    }

    public function testWindowFunctions(): void
    {
        $sql = 'SELECT id, name, salary, AVG(salary) OVER (PARTITION BY department ORDER BY hire_date ROWS BETWEEN 1 PRECEDING AND 1 FOLLOWING) AS moving_avg_salary FROM employees';
        $expected = "SELECT\n    id,\n    name,\n    salary,\n    AVG(salary) OVER (\n        PARTITION BY department\n        ORDER BY hire_date\n        ROWS BETWEEN 1 PRECEDING AND 1 FOLLOWING\n    ) AS moving_avg_salary\nFROM\n    employees";
        $this->assertEquals($expected, $this->formatter->format($sql));
    }

    public function testProcedureCreation(): void
    {
        $sql = 'CREATE PROCEDURE get_users(IN min_age INT) BEGIN SELECT * FROM users WHERE age >= min_age; END';
        $expected = "CREATE PROCEDURE get_users(IN min_age INT)\nBEGIN\n    SELECT *\n    FROM\n        users\n    WHERE\n        age >= min_age;\nEND";
        $this->assertEquals($expected, $this->formatter->format($sql));
    }

    public function testTriggerCreation(): void
    {
        $sql = 'CREATE TRIGGER before_insert_users BEFORE INSERT ON users FOR EACH ROW BEGIN SET NEW.created_at = NOW(); END';
        $expected = "CREATE TRIGGER before_insert_users\nBEFORE INSERT ON users\nFOR EACH ROW\nBEGIN\n    SET NEW.created_at = NOW();\nEND";
        $this->assertEquals($expected, $this->formatter->format($sql));
    }

    public function testTransactionStatements(): void
    {
        $sql = 'START TRANSACTION; UPDATE accounts SET balance = balance - 100 WHERE id = 1; UPDATE accounts SET balance = balance + 100 WHERE id = 2; COMMIT;';
        $expected = "START TRANSACTION;\n\nUPDATE accounts\nSET\n    balance = balance - 100\nWHERE\n    id = 1;\n\nUPDATE accounts\nSET\n    balance = balance + 100\nWHERE\n    id = 2;\n\nCOMMIT;";
        $this->assertEquals($expected, $this->formatter->format($sql));
    }

    public function testMergeStatement(): void
    {
        $sql = 'MERGE INTO target_table t USING source_table s ON (t.id = s.id) WHEN MATCHED THEN UPDATE SET t.column1 = s.column1 WHEN NOT MATCHED THEN INSERT (id, column1) VALUES (s.id, s.column1)';
        $expected = "MERGE INTO target_table t\nUSING source_table s ON (t.id = s.id)\nWHEN MATCHED THEN\n    UPDATE SET t.column1 = s.column1\nWHEN NOT MATCHED THEN\n    INSERT (\n        id,\n        column1\n    )\n    VALUES (\n        s.id,\n        s.column1\n    )";
        $this->assertEquals($expected, $this->formatter->format($sql));
    }

    public function testFixedStyleUppercasesKeywordsAndPreservesIdentifiers(): void
    {
        $sql = "select user_id, userName from user_accounts where status = 'active' and user_age > 18";
        $expected = "SELECT\n    user_id,\n    userName\nFROM\n    user_accounts\nWHERE\n    status = 'active'\n    AND user_age > 18";
        $this->assertEquals($expected, $this->formatter->format($sql));
    }

    public function testEdgeCases(): void
    {
        // Empty SQL
        $this->assertEquals('', $this->formatter->format(''));

        // Only whitespace
        $this->assertEquals('', $this->formatter->format("    \n\t   "));

        // Single keyword
        $this->assertEquals('SELECT', $this->formatter->format('SELECT'));

        // Very long identifier
        $longIdentifier = str_repeat('a', 100);
        $sql = "SELECT $longIdentifier FROM table";
        $expected = "SELECT\n    $longIdentifier\nFROM\n    table";
        $this->assertEquals($expected, $this->formatter->format($sql));
    }

    public function testCustomDatatypes(): void
    {
        $sql = 'CREATE TABLE custom_types (id INT, data JSONB, coords POINT, tags TEXT[])';
        $expected = "CREATE TABLE custom_types (\n    id INT,\n    data JSONB,\n    coords POINT,\n    tags TEXT[]\n)";
        $this->assertEquals($expected, $this->formatter->format($sql));
    }

    public function testComplexJoins(): void
    {
        $sql = 'SELECT * FROM table1 t1 LEFT JOIN table2 t2 ON t1.id = t2.id RIGHT JOIN table3 t3 ON t2.id = t3.id FULL OUTER JOIN table4 t4 ON t3.id = t4.id';
        $expected = "SELECT *\nFROM\n    table1 t1\nLEFT JOIN\n    table2 t2\n    ON t1.id = t2.id\nRIGHT JOIN\n    table3 t3\n    ON t2.id = t3.id\nFULL OUTER JOIN\n    table4 t4\n    ON t3.id = t4.id";
        $this->assertEquals($expected, $this->formatter->format($sql));
    }
}
