<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Ussd\UssdMenu;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * Africa's Talking USSD callback.
 *
 * AT POSTs sessionId, serviceCode, phoneNumber and text, and expects a plain
 * text reply starting with "CON" (keep the session open) or "END" (close it).
 */
class UssdController extends Controller
{
    public function __invoke(Request $request, UssdMenu $menu): Response
    {
        $validated = $request->validate([
            'phoneNumber' => ['required', 'string', 'max:20'],
            'text' => ['nullable', 'string', 'max:500'],
        ]);

        $agent = Agent::findByPhone($validated['phoneNumber']);

        if ($agent === null) {
            return $this->reply("END Access denied.\nContact coordinator.");
        }

        try {
            return $this->reply($menu->handle($agent, $validated['text'] ?? ''));
        } catch (Throwable $e) {
            report($e);

            return $this->reply("END Service unavailable.\nPlease try again.");
        }
    }

    private function reply(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
