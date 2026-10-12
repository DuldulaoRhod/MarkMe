Encryption and Secure Connections


1. Data Protection

Data protection is an important part of application security. The project must identify sensitive personal information stored in the MySQL database, such as names, email addresses, contact numbers, addresses, and authentication credentials. This information must be handled carefully to prevent unauthorized access, exposure, or misuse. Only authorized users should be allowed to access sensitive information based on their assigned roles and permissions.

 2. Encryption of Sensitive Data

Encryption helps protect sensitive information by converting readable data into a format that cannot be easily understood without the appropriate decryption key. Sensitive database fields should be encrypted when necessary, especially when the information must remain confidential even if unauthorized individuals gain access to stored data. Passwords must not be stored as plain text or encrypted using reversible encryption. Instead, they must be protected using secure password-hashing functions such as PHP's `password_hash()` and verified using `password_verify()`.

3. Encryption Key Management

Encryption keys must be stored securely outside the MySQL database and application source code. Storing keys in publicly accessible files or committing them to a GitHub repository can expose sensitive information and weaken the application's security. Environment variables or a dedicated secret manager should be used to manage encryption keys. Access to these keys must be restricted to authorized application processes, and keys should be backed up and rotated according to an appropriate security procedure.

4. HTTPS/TLS Configuration

HTTPS/TLS protects information transmitted between the user's browser and the web server by encrypting the connection. This helps prevent unauthorized individuals from reading or modifying sensitive information while it is being transmitted. For deployment, the application should use a valid TLS certificate, redirect HTTP traffic to HTTPS where appropriate, and configure secure communication settings. The actual availability of HTTPS/TLS depends on the hosting environment and server configuration.

5. Security Review and Protection

The application must be reviewed for common web security risks. Prepared statements should be used for database queries to help prevent SQL injection. User-generated content should be properly escaped before being displayed to reduce Cross-Site Scripting (XSS) risks. CSRF tokens should be validated for protected actions that change application data. Password verification, session handling, session cookie settings, and access controls must also be reviewed to help prevent unauthorized access and protect user accounts.

6. Implementation and Deployment Status

The security features must be checked through code inspection and testing before they are considered implemented. This includes identifying sensitive database fields, verifying password hashing, checking the use of prepared statements, reviewing output encoding and CSRF protection, and ensuring that encryption keys are stored securely. HTTPS/TLS must also be verified in the deployment environment. Any protection that has not yet been implemented or tested should be documented as pending rather than marked as complete.

Conclusion

Encryption and secure connections help protect the confidentiality and integrity of information handled by the application. By identifying sensitive data, applying appropriate encryption and password hashing, protecting encryption keys, and reviewing common web vulnerabilities, the project can improve its overall security. Regular testing and proper deployment configuration are necessary to ensure that these protections work as intended.
