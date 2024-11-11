<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function show(Request $request)
    {
        return $request->user();
    }

    // Função de registro
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create($data); // depois de validar os dados, vai criar um usuário com todos os dados (e role como user por padrão)
        $token = $user->createToken('auth_token')->plainTextToken;
        $formattedToken = explode('|', $token)[1];

        return response()->json(['message' => 'User registered successfully', 'token' => $formattedToken], 201); //o retoro contem uma confirmação e o token
    }

    // Função de login
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first(); //vai procurar o user conforme email correspondente

        if (! $user || ! Hash::check($request->password, $user->password)) { //checa se encontrou o user, e se o password está correto
            return response()->json([
                'message' => 'The provided credentials are incorrect.',
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;
        $formattedToken = explode('|', $token)[1]; // Remove o prefixo antes do pipe

        return response()->json(['token' => $formattedToken], 200); // Retorna o token formatado
    }


    // Função de logout
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete(); //valida o usuário, e apaga o token

        return response()->json(['message' => 'Logged out successfully'], 200);
    }

    // Função de atualização de usuário
    public function update(Request $request, User $user)
    {
        $authenticatedUser = $request->user();

        // Se o usuário autenticado não for admin e não for o mesmo usuário, nega o acesso
        if ($authenticatedUser->id !== $user->id && $authenticatedUser->role !== 'admin') {
            return response()->json(['message' => 'Forbidden'], 403); //Forbidden significa "sem autorização"
        }

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|string|email|max:255|unique:users,email,' . $user->id,
            'password' => 'sometimes|string|min:8|confirmed',
        ]);

        // Faz a verificação pra apenas admin poder alterar a role
        if ($authenticatedUser->role === 'admin') {
            $request->validate([
                'role' => 'sometimes|string|in:user,admin',
            ]);
        } else if ($request->has('role')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->only('name', 'email', 'password'); //criação de um array para os dados

        if ($authenticatedUser->role === 'admin' && $request->has('role')) {
            $data['role'] = $request->input('role'); //caso seja admin, inclui o campo role nos dados
        }

        $user->update($data); // faz o update dos dados

        return response()->json(['message' => 'User updated successfully'], 200);
    }

    // Função de exclusão de usuário
    public function destroy(Request $request, User $user)
    {
        // Verificar se o usuário autenticado é administrador
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $user->delete(); // deleta o usuário

        return response()->json(['message' => 'User deleted successfully'], 200);
    }
}
