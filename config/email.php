<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once 'vendor/autoload.php'; // Path to autoload.php

function sendPasswordResetEmail($to, $name, $reset_link) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com'; // Gmail SMTP
        $mail->SMTPAuth   = true;
        $mail->Username   = 'your-email@gmail.com'; // Your Gmail
        $mail->Password   = 'your-app-password'; // Gmail App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        // Alternatively, use your organization's SMTP:
        // $mail->Host = 'mail.yourgovernment.gov.zm';
        // $mail->Username = 'noreply@yourgovernment.gov.zm';
        // $mail->Password = 'your-email-password';
        // $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        // $mail->Port = 465;

        // Recipients
        $mail->setFrom('noreply@government.gov.zm', 'Inter Ministry Exchange');
        $mail->addAddress($to, $name);
        $mail->addReplyTo('support@government.gov.zm', 'Support Team');

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Password Reset Request - Inter Ministry Exchange';
        
        $message = "
        <html>
        <head>
            <title>Password Reset Request</title>
            <style>
                body { font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px; }
                .container { background: white; padding: 30px; border-radius: 10px; max-width: 600px; margin: 0 auto; }
                .header { background: #10B981; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { padding: 20px; }
                .button { background: #10B981; color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; display: inline-block; }
                .footer { margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>Inter Ministry Exchange</h2>
                    <p>Government Data Exchange Portal</p>
                </div>
                <div class='content'>
                    <h3>Hello " . htmlspecialchars($name) . ",</h3>
                    <p>You requested a password reset for your account. Click the button below to reset your password:</p>
                    <p style='text-align: center; margin: 30px 0;'>
                        <a href='$reset_link' class='button'>Reset Your Password</a>
                    </p>
                    <p>Or copy and paste this link in your browser:</p>
                    <p style='background: #f8f9fa; padding: 10px; border-radius: 5px; word-break: break-all;'>
                        <a href='$reset_link'>$reset_link</a>
                    </p>
                    <p><strong>This link will expire in 30 minutes.</strong></p>
                    <p>If you didn't request this reset, please ignore this email.</p>
                </div>
                <div class='footer'>
                    <p>This is an automated message from the Inter Ministry Exchange System.</p>
                    <p>© " . date('Y') . " Government Portal. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $mail->Body = $message;
        
        // Plain text version for non-HTML email clients
        $mail->AltBody = "Hello $name,\n\nYou requested a password reset. Use this link: $reset_link\n\nThis link expires in 30 minutes.\n\nIf you didn't request this, please ignore this email.";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email sending failed: " . $mail->ErrorInfo);
        return false;
    }
}

function sendPasswordResetConfirmation($to, $name) {
    $mail = new PHPMailer(true);
    
    try {
        // Same SMTP configuration as above
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'your-email@gmail.com';
        $mail->Password   = 'your-app-password';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('noreply@government.gov.zm', 'Inter Ministry Exchange');
        $mail->addAddress($to, $name);
        
        $mail->isHTML(true);
        $mail->Subject = 'Password Reset Successful - Inter Ministry Exchange';
        
        $message = "
        <html>
        <body>
            <h2>Password Reset Successful</h2>
            <p>Hello " . htmlspecialchars($name) . ",</p>
            <p>Your password has been successfully reset.</p>
            <p>If you did not perform this action, please contact system administrator immediately.</p>
            <br>
            <p>Best regards,<br>Inter Ministry Exchange Team</p>
        </body>
        </html>
        ";
        
        $mail->Body = $message;
        $mail->AltBody = "Hello $name,\n\nYour password has been successfully reset.\n\nIf you did not perform this action, please contact system administrator immediately.";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Confirmation email failed: " . $mail->ErrorInfo);
        return false;
    }
}
?>