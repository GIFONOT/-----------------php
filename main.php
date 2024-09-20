<?php
$servername = "127.0.0.1";
$username = "root";  
$password = "root";  
$dbname = "tender_db";
$port = 8889;  

$conn = new mysqli($servername, $username,
 $password, $dbname, $port);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
echo "Connected  BD successfully";
echo "<br> <br>";


// Функция для SQL-запроса
function insertTenderData($conn, $tenderNumber, $organizerName, $formattedDate, $tenderLink) {
    $date = DateTime::createFromFormat('d.m.Y', $formattedDate);
    $mysqlDate = $date->format('Y-m-d');

    $stmt = $conn->prepare("INSERT INTO tenders (tender_number, organizer_name, request_receiving_begin_date, tender_link) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $tenderNumber, $organizerName, $mysqlDate, $tenderLink);
    $stmt->execute();
    $tender_id = $stmt->insert_id; // ID вставленного тендера
    $stmt->close();
    return $tender_id;
}

function insertDocumentData($conn, $tender_id, $documentName, $documentLink) {
    $stmt = $conn->prepare("INSERT INTO documents (tender_id, document_name, document_link) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $tender_id, $documentName, $documentLink);
    $stmt->execute();
    $stmt->close();
}


$ch = curl_init();

curl_setopt($ch, CURLOPT_URL, "https://tender.rusal.ru/Tenders/Load");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);

// Параметры запроса
$data = http_build_query([
    'limit' => 10,
    'ClassifiersFieldData.SiteSectionType' => 'bef4c544-ba45-49b9-8e91-85d9483ff2f6',// Фильтр
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

$response = curl_exec($ch);


if (curl_errno($ch)) {
    echo 'Ошибка запроса: ' . curl_error($ch);
} else {
    // Можно привести полученный ответ в JSON и не использовать регулярные выражения
    $decodedResponse = json_decode($response, true);

    if (isset($decodedResponse['Rows']) && is_array($decodedResponse['Rows'])) {
        foreach ($decodedResponse['Rows'] as $tender) {
            $tenderNumber = $tender['TenderNumber'];
            $organizerName = $tender['OrganizerName'];
            $requestReceivingBeginDate = $tender['RequestReceivingBeginDate'];
            $dateTime = new DateTime($requestReceivingBeginDate);
            $formattedDate = $dateTime->format('d.m.Y');
            $tenderLink = "https://tender.rusal.ru/Tender/{$tenderNumber}/1";


            $tender_id = insertTenderData($conn, $tenderNumber, $organizerName, $formattedDate, $tenderLink);

            // Выводим данные
            echo "Tender Number: $tenderNumber<br>";
            echo "Organizer Name: $organizerName<br>";
            echo "Link: <a href='$tenderLink'>$tenderLink</a><br>";
            echo "Request Receiving Begin Date: $formattedDate<br>";


            // Второй запрос
            $ch2 = curl_init();
            curl_setopt($ch2, CURLOPT_URL, $tenderLink);
            curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch2, CURLOPT_POST, true);
            $response2 = curl_exec($ch2);
            curl_close($ch2);


            if (curl_errno($ch2)) {
                echo 'Ошибка запроса: ' . curl_error($ch2);
            } else {
                $pattern = '@<a href="([^"]+)"[^>]*>([^<]+)</a>@';
                preg_match_all($pattern, $response2, $matches);

                $documentFound = false;
                if (!empty($matches[1])) {
                    foreach ($matches[1] as $index => $link) {
                        $documentName = $matches[2][$index];
                        // Проверка на документ какого либо типа
                        if (preg_match('/\.(docx|pdf|doc|zip)/', $documentName)) {
                            $documentFound = true;
                
                            if (strpos($link, 'http') !== 0) {
                                $link = 'https://tender.rusal.ru' . $link;
                            }
                            
                            insertDocumentData($conn, $tender_id, $documentName, $link);
                            echo "Document Name: $documentName<br>";
                            echo "Document Link: <a href='$link'>$link</a><br>";
                        }
                    }
                }
                if (!$documentFound) {
                    echo "Документы не найдены. <br>";
                }
                echo "<br>";

                
            }
        }
    } else {
        echo 'Нет данных для отображения.';
    }
}

curl_close($ch);
$conn->close();
