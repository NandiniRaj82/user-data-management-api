<?php

namespace App\Controller;

use App\Entity\User;
use App\Controller\FileException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\HttpFoundation\JsonResponse;

class UserDataController extends AbstractController
{
     /*
     * Upload user data from a CSV file.
     * 
     * @Route("/api/upload", name="user_data_upload", methods={"POST"})
     * @IsGranted("ROLE_ADMIN")
     */ 

    public function uploadData(Request $request, MailerInterface $mailer, EntityManagerInterface $em)
    {
        $providedToken = $request->headers->get('Authorization');

    // Define your test token (this should match the one used in Postman)
    $testAdminToken = 'ADMIN';

    if ($providedToken !== $testAdminToken) {
        return new JsonResponse(['message' => 'Access denied. Admins only.'], 403);
    }
        $uploadedFile = $request->files->get('file');

        if (!$uploadedFile) {
            return new JsonResponse(['message' => 'No file uploaded.'], 400);
        }

    // Move the uploaded file to a temporary location for processing
        $destination = 'C:/xampp/htdocs/my_project3/public/uploads';
        $originalFilename = pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME);
        $newFilename = $originalFilename.'-'.uniqid().'.'.$uploadedFile->guessExtension();

        $uploadedFile->move($destination, $newFilename);

        // Now the file is available at the destination path
        $csvFilePath = $destination . '/' . $newFilename;

        if (!file_exists($csvFilePath)) {
            return new Response('CSV file not found.', 404);
        }

        $handle = fopen($csvFilePath, 'r');
        if (!$handle) {
            return new JsonResponse(['message' => 'Unable to open the CSV file.'], 500);
        }
        $users = [];

        $header = fgetcsv($handle); // Read the header

    // Check if the header was read correctly
        if (!$header) {
        fclose($handle);
        return new JsonResponse(['message' => 'CSV header not found or empty.'], 500);
    }

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) !== 5) {
                continue; // Skip incomplete rows
            }
            
            $existingUser = $em->getRepository(User::class)->findOneBy(['email' => $row[1]]);
            if ($existingUser) {
                continue; // Skip this row if user already exists
            }

            // Create a new User entity
            $user = new User();
            $user->setName($row[0]);
            $user->setEmail($row[1]);
            $user->setUsername($row[2]);
            $user->setAddress($row[3]);
            $user->setRole($row[4]);
    
            // Persist the user entity
            $em->persist($user);
            $users[] = $user; // Add the user to the array for later use
        
        }

        fclose($handle);

        $em->flush(); // Save to database
        $users = $em->getRepository(User::class)->findAll();

        if (!$users) {
        return new JsonResponse(['message' => 'No users found.'], 404);
    }

    foreach ($users as $user) {
        $email = (new Email())
            ->from('symfonyapiproject@gmail.com')  // Sender's email
            ->to($user->getEmail())  // Send email to each user
            ->subject('Welcome to the Platform')
            ->text('Hello , your data has been uploaded successfully.');

        $mailer->send($email); // Send the email
    }

    return new JsonResponse(['message' => 'Emails sent to all users.'], 200);
    
    }

    /**
     * View all users.
     * 
     * @Route("/api/users", name="view_users", methods={"GET"})
     */
    public function viewUsers(EntityManagerInterface $em)
    {
        $users = $em->getRepository(User::class)->findAll();

        if (empty($users)) {
            return new JsonResponse(['message' => 'No users found.'], 404);
        }
    
        // Map the user entities to an array for serialization
        $userData = [];
        foreach ($users as $user) {
            $userData[] = [
                'id' => $user->getId(),
                'name' => $user->getName(),
                'email' => $user->getEmail(),
                'username' => $user->getUsername(),
                'address' => $user->getAddress(),
                'role' => $user->getRole(),
            ];
        }
    
        return new JsonResponse($userData);
    }

    /**
     * Create a backup of the database.
     * 
     * @Route("/api/backup", name="backup_database", methods={"GET"})
     * @IsGranted("ROLE_ADMIN")
     */
    public function backupDatabase(Request $request)
    {
        $providedToken = $request->headers->get('Authorization');

    // Define your test token for admin access
        $testAdminToken = 'ADMIN'; // This should match the one used in Postman
    
    // Check if the provided token matches the admin token
    if ($providedToken !==  $testAdminToken) {
        return new JsonResponse(['message' => 'Access denied. Admins only.'], 403);
    }

    // Define the path to the backup directory and the full path to the backup file
    $backupDir = '../var/backup/';
    $backupFile = $backupDir . 'backup.sql'; // Backup file name fixed to backup.sql

    
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0777, true);
    }

    // Database credentials
    $user = 'root'; 
    $password = ''; 
    $database = 'data_management_db'; 

    // Construct the mysqldump command with absolute path
    $mysqldumpPath = 'C:\\xampp\\mysql\\bin\\mysqldump.exe'; // Absolute path to mysqldump
    $command = sprintf('%s --user=%s --password=%s %s > %s', $mysqldumpPath, $user, $password, $database, escapeshellarg($backupFile));

    // Execute the command and capture the output
    exec($command, $output, $returnVar);

    // Check for errors
    if ($returnVar !== 0) {
        return new Response('Database backup failed: ' . implode("\n", $output), 500);
    }

    return new Response('Database backup created: ' . $backupFile);
    
        
    }

    /**
     * Restore the database from a backup.
     * 
     * @Route("/api/restore", name="restore_database", methods={"POST"})
     */
    public function restoreDatabase(Request $request)
    {
        $providedToken = $request->headers->get('Authorization');

        // Define your test token for admin access
        $testAdminToken = 'ADMIN'; // This should match the one used in Postman
    
        // Check if the provided token matches the admin token
        if ($providedToken !== $testAdminToken) {
            return new JsonResponse(['message' => 'Access denied. Admins only.'], 403);
        }
    
        // Get the backup file name from the request
        $backupFileName = $request->files->get('file');
        if (!$backupFileName) {
            return new JsonResponse(['message' => 'No backup file provided.'], 400);
        }
    
        // Define the path to the backup file
        $backupDir = '../var/backup/';
        $backupFile = $backupDir . 'backup.sql';
    
        // Check if the backup file exists
        if (!file_exists($backupFile)) {
            return new Response('Backup file not found: ' . $backupFile, 404);
        }
    
        // Database credentials
        $user = 'root';
        $password = '';
        $database = 'data_management_db';
    
        // Construct the mysql restore command
        $command = sprintf('mysql --user=%s --password=%s %s < %s', $user, $password, $database, escapeshellarg($backupFile));
    
        // Execute the command and capture the output
        exec($command, $output, $returnVar);
    
        // Check for errors
        if ($returnVar !== 0) {
            return new Response('Database restore failed: ' . implode("\n", $output), 500);
        }
    
        return new Response('Database restored successfully from: ' . $backupFile);
    }
}
