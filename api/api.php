<?php
// Устанавливаем заголовки для CORS и JSON-ответов
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Путь к файлу с данными пользователей
define('DATA_PATH', __DIR__ . '/data/');
define('USERS_FILE', DATA_PATH . 'users.json');

// --- СПИСОК ДОСТУПНЫХ БИЗНЕСОВ ---
// В реальном проекте это может быть вынесено в отдельный файл или базу данных
function get_available_businesses() {
    return [
        [
            "id" => 1,
            "name" => "Лимонадный стенд",
            "cost" => 500,
            "income_per_hour" => 25
        ],
        [
            "id" => 2,
            "name" => "Магазинчик у дома",
            "cost" => 5000,
            "income_per_hour" => 200
        ],
        [
            "id" => 3,
            "name" => "Небольшое кафе",
            "cost" => 25000,
            "income_per_hour" => 1100
        ],
        [
            "id" => 4,
            "name" => "IT-стартап",
            "cost" => 150000,
            "income_per_hour" => 7500
        ]
    ];
}

// --- ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ---

// Функция для чтения данных пользователей из файла
function get_users() {
    if (!file_exists(USERS_FILE)) {
        // Если папки data нет, создаем ее
        if (!is_dir(DATA_PATH)) {
            mkdir(DATA_PATH, 0777, true);
        }
        file_put_contents(USERS_FILE, json_encode([]));
        return [];
    }
    $data = file_get_contents(USERS_FILE);
    return json_decode($data, true);
}

// Функция для сохранения данных пользователей в файл
function save_users($users) {
    file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Функция для поиска пользователя по имени
function find_user_by_username($username) {
    $users = get_users();
    foreach ($users as $key => $user) {
        if (strtolower($user['username']) === strtolower($username)) {
            return ['key' => $key, 'data' => $user];
        }
    }
    return null;
}

// --- ОСНОВНАЯ ЛОГИКА API ---

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);

switch ($action) {
    // --- АУТЕНТИФИКАЦИЯ ---
    case 'register':
        $username = $input['username'] ?? '';
        $password = $input['password'] ?? '';

        if (empty($username) || empty($password)) {
            echo json_encode(['success' => false, 'error' => 'Имя пользователя и пароль не могут быть пустыми.']);
            exit;
        }

        if (find_user_by_username($username)) {
            echo json_encode(['success' => false, 'error' => 'Пользователь с таким именем уже существует.']);
            exit;
        }

        $users = get_users();
        $newUser = [
            'username' => $username,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'balance' => 100, // Стартовый баланс
            'last_work_time' => 0,
            'businesses' => []
        ];
        $users[] = $newUser;
        save_users($users);
        echo json_encode(['success' => true]);
        break;

    case 'login':
        $username = $input['username'] ?? '';
        $password = $input['password'] ?? '';
        
        $user_info = find_user_by_username($username);

        if ($user_info && password_verify($password, $user_info['data']['password'])) {
            // Успешный вход, но не отправляем пароль обратно
            unset($user_info['data']['password']);
            echo json_encode(['success' => true, 'user' => $user_info['data']]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Неверное имя пользователя или пароль.']);
        }
        break;

    // --- ИГРОВЫЕ ДЕЙСТВИЯ ---
    case 'getUserData':
        $username = $input['username'] ?? '';
        $user_info = find_user_by_username($username);
        if ($user_info) {
             unset($user_info['data']['password']);
             echo json_encode(['success' => true, 'user' => $user_info['data']]);
        } else {
             echo json_encode(['success' => false, 'error' => 'Пользователь не найден.']);
        }
        break;
        
    case 'work':
        $username = $input['username'] ?? '';
        $user_info = find_user_by_username($username);

        if (!$user_info) {
            echo json_encode(['success' => false, 'error' => 'Пользователь не найден.']);
            exit;
        }
        
        $user = $user_info['data'];
        $cooldown = 60; // 60 секунд
        $currentTime = time();
        
        if ($currentTime - $user['last_work_time'] < $cooldown) {
            echo json_encode(['success' => false, 'error' => 'Вы слишком часто работаете. Подождите.']);
            exit;
        }
        
        $earnings = rand(50, 200);
        $users = get_users();
        $users[$user_info['key']]['balance'] += $earnings;
        $users[$user_info['key']]['last_work_time'] = $currentTime;
        save_users($users);
        
        echo json_encode([
            'success' => true, 
            'message' => "Вы заработали $earnings $!", 
            'newBalance' => $users[$user_info['key']]['balance'],
            'last_work_time' => $currentTime
        ]);
        break;

    case 'getBusinesses':
        echo json_encode(['success' => true, 'businesses' => get_available_businesses()]);
        break;
        
    case 'buyBusiness':
        $username = $input['username'] ?? '';
        $businessId = $input['businessId'] ?? 0;
        
        $user_info = find_user_by_username($username);
        if (!$user_info) {
            echo json_encode(['success' => false, 'error' => 'Пользователь не найден.']);
            exit;
        }
        
        $available_businesses = get_available_businesses();
        $business_to_buy = null;
        foreach($available_businesses as $b) {
            if ($b['id'] == $businessId) {
                $business_to_buy = $b;
                break;
            }
        }
        
        if (!$business_to_buy) {
            echo json_encode(['success' => false, 'error' => 'Такого бизнеса не существует.']);
            exit;
        }
        
        $user = $user_info['data'];
        
        // Проверяем, есть ли у пользователя уже этот бизнес
        foreach($user['businesses'] as $owned_b) {
            if ($owned_b['id'] == $businessId) {
                echo json_encode(['success' => false, 'error' => 'У вас уже есть этот бизнес.']);
                exit;
            }
        }
        
        if ($user['balance'] < $business_to_buy['cost']) {
            echo json_encode(['success' => false, 'error' => 'Недостаточно средств.']);
            exit;
        }
        
        $users = get_users();
        $users[$user_info['key']]['balance'] -= $business_to_buy['cost'];
        $new_business = [
            "id" => $business_to_buy['id'],
            "purchase_time" => time(),
            "last_collection_time" => time()
        ];
        $users[$user_info['key']]['businesses'][] = $new_business;
        save_users($users);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Вы успешно купили "' . $business_to_buy['name'] . '"!',
            'newBalance' => $users[$user_info['key']]['balance'],
            'newBusiness' => $new_business
        ]);
        break;

    case 'collectIncome':
        $username = $input['username'] ?? '';
        
        $user_info = find_user_by_username($username);
        if (!$user_info) {
            echo json_encode(['success' => false, 'error' => 'Пользователь не найден.']);
            exit;
        }
        
        $user = $user_info['data'];
        $available_businesses = get_available_businesses();
        $currentTime = time();
        $total_income = 0;
        
        $users = get_users();
        
        // Проходимся по всем бизнесам пользователя и обновляем их
        foreach($user['businesses'] as $key => $owned_business) {
            $business_details = null;
            foreach($available_businesses as $b) {
                if ($b['id'] == $owned_business['id']) {
                    $business_details = $b;
                    break;
                }
            }
            
            if ($business_details) {
                $seconds_passed = $currentTime - $owned_business['last_collection_time'];
                $income_per_second = $business_details['income_per_hour'] / 3600;
                $earned = floor($seconds_passed * $income_per_second);
                
                if($earned > 0) {
                    $total_income += $earned;
                    // Обновляем время последнего сбора в массиве $users
                    $users[$user_info['key']]['businesses'][$key]['last_collection_time'] = $currentTime;
                }
            }
        }
        
        if ($total_income > 0) {
            $users[$user_info['key']]['balance'] += $total_income;
            save_users($users);
            
            echo json_encode([
                'success' => true,
                'message' => "Вы собрали $total_income $ дохода!",
                'newBalance' => $users[$user_info['key']]['balance'],
                'updatedBusinesses' => $users[$user_info['key']]['businesses']
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Пока нечего собирать.']);
        }
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Неизвестное действие']);
        break;
}
