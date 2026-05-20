<?php

namespace App\Scripts;

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Entity\ChatMessage;
use App\Entity\Conversation;
use App\Entity\ConversationParticipant;
use App\Entity\KanbanTask;
use App\Entity\Product;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;

/**
 * Seeds the database with a Super Admin and sample data for all tables.
 *
 * Usage:
 *   php bin/console app:seed-database
 *
 * Safe to re-run — existing records (matched by email / serial / title) are skipped.
 */
class SeedDatabaseCommand extends Command
{
    protected static $defaultName = 'app:seed-database';
    protected static $defaultDescription = 'Seeds the database with a Super Admin user and sample data for all tables.';

    private EntityManagerInterface $em;
    private UserPasswordEncoderInterface $encoder;

    public function __construct(EntityManagerInterface $em, UserPasswordEncoderInterface $encoder)
    {
        parent::__construct();
        $this->em = $em;
        $this->encoder = $encoder;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('🌱  Intranet Database Seeder');

        // ── 1. USERS ──────────────────────────────────────────────────────────
        $io->section('Users');

        $usersData = [
            [
                'email'             => 'admin@intranet.com',
                'name'              => 'Administrador',
                'surname'           => 'Principal',
                'password'          => 'admin',
                'roles'             => ['ROLE_SUPER_ADMIN'],
                'mustChangePassword' => false,
            ],
            [
                'email'             => 'jlopez@intranet.com',
                'name'              => 'Juan',
                'surname'           => 'López',
                'password'          => '123',
                'roles'             => ['ROLE_ADMIN'],
                'mustChangePassword' => true,
            ],
            [
                'email'             => 'mgarcia@intranet.com',
                'name'              => 'María',
                'surname'           => 'García',
                'password'          => '123',
                'roles'             => ['ROLE_USER'],
                'mustChangePassword' => true,
            ],
            [
                'email'             => 'logistica@intranet.com',
                'name'              => 'Logística',
                'surname'           => 'Inventario',
                'password'          => '123',
                'roles'             => ['ROLE_LOGISTICS'],
                'mustChangePassword' => true,
            ],
        ];

        $userRepo  = $this->em->getRepository(User::class);
        $createdUsers = [];

        foreach ($usersData as $userData) {
            $existing = $userRepo->findOneBy(['email' => $userData['email']]);
            $user = $existing ?: new User();

            $user->setEmail($userData['email']);
            $user->setName($userData['name']);
            $user->setSurname($userData['surname']);
            $user->setRoles($userData['roles']);
            $user->setMustChangePassword($userData['mustChangePassword']);
            $user->setPassword($this->encoder->encodePassword($user, $userData['password']));

            $this->em->persist($user);
            $createdUsers[$userData['email']] = $user;

            if ($existing) {
                $io->text("  Updated: <info>{$userData['email']}</info>");
            } else {
                $io->text("  Created: <info>{$userData['email']}</info> [" . implode(', ', $userData['roles']) . "]");
            }
        }

        $this->em->flush();

        // Ensure we always have a reference to the super admin for relations
        $superAdmin = $createdUsers['admin@intranet.com'];

        // ── 2. PRODUCTS ───────────────────────────────────────────────────────
        $io->section('Products');

        $productsData = [
            [
                'nombre'          => 'Laptop Dell XPS 15',
                'categoria'       => 'Computadoras',
                'marca'           => 'Dell',
                'modelo'          => 'XPS 15 9530',
                'caracteristicas' => 'Intel Core i7, 16GB RAM, 512GB SSD, Pantalla OLED 15.6"',
                'color'           => 'Plata',
                'serial'          => 'DXPS15-001',
                'condicion'       => 'Bueno',
                'locacion'        => 'Oficina A',
                'cantidad'        => 5,
                'empresa'         => 'JPL',
            ],
            [
                'nombre'          => 'Monitor LG UltraWide',
                'categoria'       => 'Monitores',
                'marca'           => 'LG',
                'modelo'          => '34WN80C-B',
                'caracteristicas' => '34" IPS, Res. 3440x1440, 60Hz, Puerto USB-C',
                'color'           => 'Negro',
                'serial'          => 'LGUW34-002',
                'condicion'       => 'Excelente',
                'locacion'        => 'Sala de Reuniones',
                'cantidad'        => 10,
                'empresa'         => 'PAFAR',
            ],
            [
                'nombre'          => 'Teclado Mecánico Logitech MX',
                'categoria'       => 'Periféricos',
                'marca'           => 'Logitech',
                'modelo'          => 'MX Keys S',
                'caracteristicas' => 'Teclado inalámbrico, retroiluminado, multidispositivo',
                'color'           => 'Grafito',
                'serial'          => 'LMXKS-003',
                'condicion'       => 'Nuevo',
                'locacion'        => 'Almacén',
                'cantidad'        => 20,
                'empresa'         => '3D3',
            ],
            [
                'nombre'          => 'Impresora HP LaserJet',
                'categoria'       => 'Impresoras',
                'marca'           => 'HP',
                'modelo'          => 'M404dn',
                'caracteristicas' => 'Impresión láser monocromo, doble cara automática',
                'color'           => 'Blanco',
                'serial'          => 'HPLJ-004',
                'condicion'       => 'Bueno',
                'locacion'        => 'Recepción',
                'cantidad'        => 2,
                'empresa'         => 'JPL',
            ],
            [
                'nombre'          => 'Router TP-Link Archer',
                'categoria'       => 'Redes',
                'marca'           => 'TP-Link',
                'modelo'          => 'AX50',
                'caracteristicas' => 'Wi-Fi 6, Doble Banda, 4 Antenas Externas',
                'color'           => 'Negro',
                'serial'          => 'TPLK-005',
                'condicion'       => 'Nuevo',
                'locacion'        => 'Servidores',
                'cantidad'        => 3,
                'empresa'         => 'PAFAR',
            ],
            [
                'nombre'          => 'Disco Duro Externo WD',
                'categoria'       => 'Almacenamiento',
                'marca'           => 'Western Digital',
                'modelo'          => 'My Passport 2TB',
                'caracteristicas' => 'USB 3.0, cifrado por hardware, software de respaldo',
                'color'           => 'Azul',
                'serial'          => 'WDMP-006',
                'condicion'       => 'Excelente',
                'locacion'        => 'Almacén',
                'cantidad'        => 15,
                'empresa'         => '3D3',
            ],
            [
                'nombre'          => 'Mouse Ergonómico Microsoft',
                'categoria'       => 'Periféricos',
                'marca'           => 'Microsoft',
                'modelo'          => 'Sculpt',
                'caracteristicas' => 'Diseño ergonómico, tecnología BlueTrack, inalámbrico',
                'color'           => 'Negro',
                'serial'          => 'MSFT-007',
                'condicion'       => 'Bueno',
                'locacion'        => 'Oficina B',
                'cantidad'        => 12,
                'empresa'         => 'JPL',
            ],
            [
                'nombre'          => 'Switch Cisco Catalyst',
                'categoria'       => 'Redes',
                'marca'           => 'Cisco',
                'modelo'          => '2960-X',
                'caracteristicas' => '24 Puertos Gigabit, administrable, capa 2',
                'color'           => 'Gris',
                'serial'          => 'CSCO-008',
                'condicion'       => 'Reacondicionado',
                'locacion'        => 'Data Center',
                'cantidad'        => 4,
                'empresa'         => 'PAFAR',
            ],
            [
                'nombre'          => 'Tablet Samsung Galaxy Tab',
                'categoria'       => 'Móviles',
                'marca'           => 'Samsung',
                'modelo'          => 'S8 Ultra',
                'caracteristicas' => 'Pantalla 14.6", 256GB, S-Pen incluido',
                'color'           => 'Gris',
                'serial'          => 'SAMG-009',
                'condicion'       => 'Excelente',
                'locacion'        => 'Dirección',
                'cantidad'        => 1,
                'empresa'         => '3D3',
            ],
            [
                'nombre'          => 'Silla de Oficina Ergonómica',
                'categoria'       => 'Mobiliario',
                'marca'           => 'Herman Miller',
                'modelo'          => 'Aeron',
                'caracteristicas' => 'Ajuste lumbar, malla transpirable, soporte postural',
                'color'           => 'Grafito',
                'serial'          => 'HM-010',
                'condicion'       => 'Bueno',
                'locacion'        => 'Oficina A',
                'cantidad'        => 8,
                'empresa'         => 'JPL',
            ],
        ];

        $productRepo = $this->em->getRepository(Product::class);

        foreach ($productsData as $pd) {
            $existing = $productRepo->findOneBy(['serial' => $pd['serial']]);
            $product = $existing ?: new Product();

            $product->setNombre($pd['nombre']);
            $product->setCategoria($pd['categoria']);
            $product->setMarca($pd['marca']);
            $product->setModelo($pd['modelo']);
            $product->setCaracteristicas($pd['caracteristicas']);
            $product->setColor($pd['color']);
            $product->setSerial($pd['serial']);
            $product->setCondicion($pd['condicion']);
            $product->setLocacion($pd['locacion']);
            $product->setCantidad($pd['cantidad']);
            $product->setEmpresa($pd['empresa'] ?? null);
            
            // Set a random registration date within the last 30 days
            $date = new \DateTime();
            $date->modify('-' . rand(0, 30) . ' days');
            $product->setRegisteredAt($date);

            $this->em->persist($product);

            if ($existing) {
                $io->text("  🔄 Updated: <info>{$pd['nombre']}</info> (serial: {$pd['serial']})");
            } else {
                $io->text("  ✅ Created: <info>{$pd['nombre']}</info> (serial: {$pd['serial']})");
            }
        }

        $this->em->flush();

        // ── 3. KANBAN TASKS ───────────────────────────────────────────────────
        $io->section('Kanban Tasks');

        $tasksData = [
            [
                'title'      => 'Configurar VPN corporativa',
                'category'   => ['IT'],
                'importance' => KanbanTask::IMPORTANCE_HIGH,
                'status'     => KanbanTask::STATUS_IN_PROGRESS,
                'subTasks'   => [
                    ['title' => 'Instalar cliente OpenVPN', 'isCompleted' => true],
                    ['title' => 'Configurar certificados', 'isCompleted' => false],
                ],
            ],
            [
                'title'      => 'Inventario de equipos Q2',
                'category'   => ['Administración'],
                'importance' => KanbanTask::IMPORTANCE_MEDIUM,
                'status'     => KanbanTask::STATUS_TODO,
                'subTasks'   => [
                    ['title' => 'Listar activos fijos', 'isCompleted' => false],
                    ['title' => 'Actualizar hoja de cálculo', 'isCompleted' => false],
                ],
            ],
            [
                'title'      => 'Actualizar política de contraseñas',
                'category'   => ['Seguridad'],
                'importance' => KanbanTask::IMPORTANCE_HIGH,
                'status'     => KanbanTask::STATUS_BACKLOG,
                'subTasks'   => [],
            ],
        ];

        $taskRepo = $this->em->getRepository(KanbanTask::class);

        foreach ($tasksData as $td) {
            $existing = $taskRepo->findOneBy(['title' => $td['title']]);
            if ($existing) {
                $io->text("  Skipped (title exists): <comment>{$td['title']}</comment>");
                continue;
            }

            $task = new KanbanTask();
            $task->setTitle($td['title']);
            $task->setCategory($td['category']);
            $task->setImportance($td['importance']);
            $task->setStatus($td['status']);
            $task->setSubTasks($td['subTasks']);
            $task->setOwner($superAdmin);

            $this->em->persist($task);
            $io->text("  Created: <info>{$td['title']}</info> [{$td['status']}]");
        }

        $this->em->flush();

        // ── 4. CHAT MESSAGES ──────────────────────────────────────────────────
        $io->section('Chat Conversations & Messages');

        $adminUser = $createdUsers['jlopez@intranet.com'] ?? null;
        $regularUser = $createdUsers['mgarcia@intranet.com'] ?? null;

        if ($adminUser && $regularUser) {
            // 1. Create a Private Conversation
            $privateConv = new Conversation();
            $privateConv->setType('private');
            $this->em->persist($privateConv);

            $p1 = new ConversationParticipant();
            $p1->setUser($superAdmin);
            $p1->setConversation($privateConv);
            $this->em->persist($p1);

            $p2 = new ConversationParticipant();
            $p2->setUser($adminUser);
            $p2->setConversation($privateConv);
            $this->em->persist($p2);

            $msg1 = new ChatMessage();
            $msg1->setContent("Hola Juan, ¿cómo va el inventario?");
            $msg1->setSender($superAdmin);
            $msg1->setConversation($privateConv);
            $this->em->persist($msg1);

            $io->text("  Created private conversation between Super Admin and Admin.");

            // 2. Create a Group Conversation
            $groupConv = new Conversation();
            $groupConv->setType('group');
            $groupConv->setName('General IT');
            $this->em->persist($groupConv);

            foreach ([$superAdmin, $adminUser, $regularUser] as $u) {
                $p = new ConversationParticipant();
                $p->setUser($u);
                $p->setConversation($groupConv);
                $this->em->persist($p);
            }

            $msg2 = new ChatMessage();
            $msg2->setContent("¡Bienvenidos al canal de IT!");
            $msg2->setSender($superAdmin);
            $msg2->setConversation($groupConv);
            $this->em->persist($msg2);

            $io->text("  Created group conversation 'General IT'.");
        }

        $this->em->flush();

        // ── Summary ───────────────────────────────────────────────────────────
        $io->success([
            'Database seeded successfully!',
            '',
            '  Super Admin  →  admin@intranet.com  /  admin',
            '  Admin        →  jlopez@intranet.com  /  123',
            '  Logistics    →  logistica@intranet.com / 123',
            '  User         →  mgarcia@intranet.com  /  123',
            '  User         →  cperez@intranet.com   /  123',
        ]);

        return Command::SUCCESS;
    }
}
