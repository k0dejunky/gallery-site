<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\ChatQuestionnaire;

class QuestionnaireController extends Controller
{
    public function __construct($request)
    {
        parent::__construct($request);
        Auth::requireUser();
    }

    public function answer(int $id): void
    {
        $user = Auth::requireUser();
        $userId = (int) $user['id'];
        $answers = (array) $this->request->post('answers', []);
        $res = ChatQuestionnaire::answer($id, $userId, $answers);
        if (!$res['ok']) {
            $this->flash('error', $res['error']);
            $this->redirect('/chat#questionnaire-' . $id);
            return;
        }
        $this->flash('success', 'Thank you for completing the questionnaire.');
        $this->redirect('/chat#questionnaire-' . $id);
    }
}
