<?php
/**
 * Developed by Rameez Scripts
 * WhatsApp: https://wa.me/923224083545 (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 *
 * Language Translation Helper
 * Returns JSON translation dictionary based on user/system language preference
 * Receipts are ALWAYS in French regardless of UI language
 */
require_once 'config.php';

header('Content-Type: application/json');

// Determine language: user preference > system default > fallback
$lang = 'fr'; // default
if (isset($_SESSION['user_id'])) {
    try {
        $conn = getDBConnection();
        $check = $conn->query("SHOW COLUMNS FROM users LIKE 'language_preference'");
        if ($check && $check->num_rows > 0) {
            $stmt = $conn->prepare("SELECT language_preference FROM users WHERE id = ?");
            $stmt->bind_param("i", $_SESSION['user_id']);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res->num_rows > 0) {
                $userLang = $res->fetch_assoc()['language_preference'];
                if ($userLang) $lang = $userLang;
            }
            $stmt->close();
        }
        $conn->close();
    } catch (Exception $e) {
        // fallback
    }
}

// If no user preference, check system default
if ($lang === 'fr') {
    $sysLang = getSetting('default_language', 'fr');
    if ($sysLang) $lang = $sysLang;
}

// Override via GET parameter (for testing)
if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'fr'])) {
    $lang = $_GET['lang'];
}

$translations = [
    'en' => [
        // Sidebar - Operations
        'dashboard' => 'Dashboard',
        'purchases' => 'Purchases',
        'sales' => 'Sales',
        'deliveries_out' => 'Deliveries Out',
        'payments' => 'Payments',
        // Sidebar - Master Data
        'supplier_master' => 'Supplier Master',
        'customer_master' => 'Customer Master',
        'pricing_master' => 'Pricing Master',
        // Sidebar - Finance
        'financing' => 'Financing',
        'expenses' => 'Expenses',
        'profit_analysis' => 'Profit Analysis',
        // Sidebar - AI
        'ai_reports' => 'AI Reports',
        // Sidebar - Logistics
        'fleet_drivers' => 'Fleet & Drivers',
        'bags_log' => 'Bags Log',
        'inventory' => 'Inventory',
        // Sidebar - Administration
        'user_management' => 'User Management',
        'settings' => 'Settings',
        'my_account' => 'My Account',
        'my_profile' => 'My Profile',
        'system' => 'System',
        'activity_logs' => 'Activity Logs',
        // Common
        'welcome' => 'Welcome',
        'add' => 'Add',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'save' => 'Save',
        'cancel' => 'Cancel',
        'close' => 'Close',
        'search' => 'Search',
        'filter' => 'Filter',
        'refresh' => 'Refresh',
        'export' => 'Export',
        'print' => 'Print',
        'actions' => 'Actions',
        'status' => 'Status',
        'date' => 'Date',
        'amount' => 'Amount',
        'total' => 'Total',
        'balance' => 'Balance',
        'active' => 'Active',
        'inactive' => 'Inactive',
        'season' => 'Season',
        'notes' => 'Notes',
        'loading' => 'Loading...',
        'no_data' => 'No data available',
        'confirm_delete' => 'Are you sure you want to delete this?',
        'success' => 'Success',
        'error' => 'Error',
        'access_denied' => 'Access denied',
        'dark_mode' => 'Dark Mode',
        'light_mode' => 'Light Mode',
        'logout' => 'Logout',
        // Forms
        'date_from' => 'Date From',
        'date_to' => 'Date To',
        'select' => 'Select...',
        'required' => 'Required',
        'optional' => 'Optional',
        // Purchases
        'supplier' => 'Supplier',
        'weight_kg' => 'Weight (kg)',
        'num_bags' => 'Number of Bags',
        'price_per_kg' => 'Price/Kg',
        'total_cost' => 'Total Cost',
        'warehouse' => 'Warehouse',
        'location' => 'Location',
        'kor' => 'KOR / Out Turn',
        'grainage' => 'Grainage',
        'quality' => 'Visual Quality',
        'receipt_number' => 'Receipt Number',
        'payment_status' => 'Payment Status',
        // Sales
        'customer' => 'Customer',
        'delivery' => 'Delivery',
        'selling_price' => 'Selling Price',
        'refraction' => 'Refraction',
        'net_weight' => 'Net Weight',
        'gross_sale' => 'Gross Sale Amount',
        'net_profit' => 'Net Profit',
        'profit_per_kg' => 'Profit/Kg',
        'interest_amount' => 'Interest Amount',
        // Deliveries
        'vehicle' => 'Vehicle',
        'driver' => 'Driver',
        'transport_cost' => 'Transport Cost',
        'loading_cost' => 'Loading Cost',
        'other_cost' => 'Other Cost',
        'weight_at_destination' => 'Weight at Destination',
        // Financing
        'direction' => 'Direction',
        'incoming' => 'Incoming',
        'outgoing' => 'Outgoing',
        'counterparty' => 'Counterparty',
        'balance_due' => 'Balance Due',
        'amount_repaid' => 'Amount Repaid',
        'interest_per_kg' => 'Interest per Kg',
        'expected_volume' => 'Expected Volume',
        'delivered_volume' => 'Delivered Volume',
        // Fleet
        'registration' => 'Registration',
        'driver_name' => 'Driver Name',
        'license_expiry' => 'License Expiry',
        'salary' => 'Salary',
        'salary_payments' => 'Salary Payments',
        // Inventory
        'stock_ledger' => 'Stock Ledger',
        'current_stock' => 'Current Stock',
        'total_in' => 'Total IN',
        'total_out' => 'Total OUT',
        'movement_type' => 'Type',
        'reference' => 'Reference',
        'running_balance' => 'Running Balance',
        // Settings
        'company_name' => 'Company Name',
        'company_address' => 'Company Address',
        'currency' => 'Currency',
        'language' => 'Language',
        'target_profit' => 'Target Profit per Kg',
        'seasons' => 'Seasons',
        'set_active' => 'Set Active',
    ],
    'fr' => [
        // Sidebar - Operations
        'dashboard' => 'Tableau de Bord',
        'purchases' => 'Achats',
        'sales' => 'Ventes',
        'deliveries_out' => 'Livraisons Sortantes',
        'payments' => 'Paiements',
        // Sidebar - Master Data
        'supplier_master' => 'Fournisseurs',
        'customer_master' => 'Clients',
        'pricing_master' => 'Tarification',
        // Sidebar - Finance
        'financing' => 'Financement',
        'expenses' => 'Depenses',
        'profit_analysis' => 'Analyse des Profits',
        // Sidebar - AI
        'ai_reports' => 'Rapports IA',
        // Sidebar - Logistics
        'fleet_drivers' => 'Flotte & Chauffeurs',
        'bags_log' => 'Journal des Sacs',
        'inventory' => 'Inventaire',
        // Sidebar - Administration
        'user_management' => 'Gestion des Utilisateurs',
        'settings' => 'Parametres',
        'my_account' => 'Mon Compte',
        'my_profile' => 'Mon Profil',
        'system' => 'Systeme',
        'activity_logs' => 'Journal d\'Activite',
        // Common
        'welcome' => 'Bienvenue',
        'add' => 'Ajouter',
        'edit' => 'Modifier',
        'delete' => 'Supprimer',
        'save' => 'Enregistrer',
        'cancel' => 'Annuler',
        'close' => 'Fermer',
        'search' => 'Rechercher',
        'filter' => 'Filtrer',
        'refresh' => 'Actualiser',
        'export' => 'Exporter',
        'print' => 'Imprimer',
        'actions' => 'Actions',
        'status' => 'Statut',
        'date' => 'Date',
        'amount' => 'Montant',
        'total' => 'Total',
        'balance' => 'Solde',
        'active' => 'Actif',
        'inactive' => 'Inactif',
        'season' => 'Saison',
        'notes' => 'Notes',
        'loading' => 'Chargement...',
        'no_data' => 'Aucune donnee disponible',
        'confirm_delete' => 'Etes-vous sur de vouloir supprimer?',
        'success' => 'Succes',
        'error' => 'Erreur',
        'access_denied' => 'Acces refuse',
        'dark_mode' => 'Mode Sombre',
        'light_mode' => 'Mode Clair',
        'logout' => 'Deconnexion',
        // Forms
        'date_from' => 'Date Debut',
        'date_to' => 'Date Fin',
        'select' => 'Selectionner...',
        'required' => 'Obligatoire',
        'optional' => 'Facultatif',
        // Purchases
        'supplier' => 'Fournisseur',
        'weight_kg' => 'Poids (kg)',
        'num_bags' => 'Nombre de Sacs',
        'price_per_kg' => 'Prix/Kg',
        'total_cost' => 'Cout Total',
        'warehouse' => 'Entrepot',
        'location' => 'Localite',
        'kor' => 'KOR / Rendement',
        'grainage' => 'Grainage',
        'quality' => 'Qualite Visuelle',
        'receipt_number' => 'Numero de Recu',
        'payment_status' => 'Statut de Paiement',
        // Sales
        'customer' => 'Client',
        'delivery' => 'Livraison',
        'selling_price' => 'Prix de Vente',
        'refraction' => 'Refraction',
        'net_weight' => 'Poids Net',
        'gross_sale' => 'Montant Brut de Vente',
        'net_profit' => 'Benefice Net',
        'profit_per_kg' => 'Benefice/Kg',
        'interest_amount' => 'Montant des Interets',
        // Deliveries
        'vehicle' => 'Vehicule',
        'driver' => 'Chauffeur',
        'transport_cost' => 'Cout de Transport',
        'loading_cost' => 'Cout de Chargement',
        'other_cost' => 'Autres Couts',
        'weight_at_destination' => 'Poids a Destination',
        // Financing
        'direction' => 'Direction',
        'incoming' => 'Entrant',
        'outgoing' => 'Sortant',
        'counterparty' => 'Contrepartie',
        'balance_due' => 'Solde Du',
        'amount_repaid' => 'Montant Rembourse',
        'interest_per_kg' => 'Interet par Kg',
        'expected_volume' => 'Volume Prevu',
        'delivered_volume' => 'Volume Livre',
        // Fleet
        'registration' => 'Immatriculation',
        'driver_name' => 'Nom du Chauffeur',
        'license_expiry' => 'Expiration du Permis',
        'salary' => 'Salaire',
        'salary_payments' => 'Paiements de Salaire',
        // Inventory
        'stock_ledger' => 'Registre de Stock',
        'current_stock' => 'Stock Actuel',
        'total_in' => 'Total Entrees',
        'total_out' => 'Total Sorties',
        'movement_type' => 'Type',
        'reference' => 'Reference',
        'running_balance' => 'Solde Courant',
        // Settings
        'company_name' => 'Nom de la Societe',
        'company_address' => 'Adresse de la Societe',
        'currency' => 'Devise',
        'language' => 'Langue',
        'target_profit' => 'Benefice Cible par Kg',
        'seasons' => 'Saisons',
        'set_active' => 'Definir comme Active',
    ]
];

echo json_encode([
    'lang' => $lang,
    'translations' => $translations[$lang] ?? $translations['fr']
]);
?>
