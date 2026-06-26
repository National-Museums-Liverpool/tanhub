<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class DataSourcesSeeder extends Seeder
{
    public function run(): void
    {
        $table = $this->db->table('data_sources');

        $rows = [
            [
                'id' => 1,
                'title' => 'NBN Atlas',
                'url' => 'https://species.nbnatlas.org',
            ],
            [
                'id' => 2,
                'title' => 'iRecord',
                'url' => 'https://irecord.org.uk',
            ],
        ];

        foreach ($rows as $row) {
            $existing = $table->where('id', $row['id'])->get()->getRowArray();

            if ($existing === null) {
                $table->insert($row);
                continue;
            }

            $table->where('id', $row['id'])->update([
                'title' => $row['title'],
                'url' => $row['url'],
            ]);
        }
    }
}
