<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Builds a ready-made iOS Shortcut (.shortcut plist) that dictates text and posts it to the
 * shopping-list endpoint, with a freshly issued access token embedded. The user only has to open
 * it in the Shortcuts app and save – no manual setup. Each download issues its own token so it can
 * be revoked on its own.
 */
class SiriShortcutController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $token = ApiToken::issue(Auth::user(), 'Siri / '.__('Shortcut'));
        $endpoint = url('api/v1/shopping/quick');
        $name = __('Add to shopping list');

        $plist = $this->buildPlist($name, $endpoint, $token);

        return response($plist, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.(Str::slug($name, '-', 'de') ?: 'einkaufsliste').'.shortcut"',
        ]);
    }

    /**
     * A minimal, importable shortcut: "Dictate Text" → "Get Contents of URL" (POST, JSON {text}),
     * with the Authorization/Accept headers set and the dictated text wired into the body.
     */
    private function buildPlist(string $name, string $endpoint, string $token): string
    {
        $dictateUuid = (string) Str::uuid();
        $outputName = __('Dictated text');
        $e = fn (string $value) => htmlspecialchars($value, ENT_XML1);
        // The object-replacement character marks where the dictated-text variable is substituted.
        $placeholder = "\u{FFFC}";

        return <<<PLIST
            <?xml version="1.0" encoding="UTF-8"?>
            <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
            <plist version="1.0">
            <dict>
                <key>WFWorkflowClientVersion</key>
                <string>900</string>
                <key>WFWorkflowMinimumClientVersion</key>
                <integer>900</integer>
                <key>WFWorkflowMinimumClientVersionString</key>
                <string>900</string>
                <key>WFWorkflowIcon</key>
                <dict>
                    <key>WFWorkflowIconStartColor</key>
                    <integer>2846468607</integer>
                    <key>WFWorkflowIconGlyphNumber</key>
                    <integer>59511</integer>
                </dict>
                <key>WFWorkflowImportQuestions</key>
                <array/>
                <key>WFWorkflowTypes</key>
                <array>
                    <string>NCWidget</string>
                    <string>WatchKit</string>
                </array>
                <key>WFWorkflowActions</key>
                <array>
                    <dict>
                        <key>WFWorkflowActionIdentifier</key>
                        <string>is.workflow.actions.dictatetext</string>
                        <key>WFWorkflowActionParameters</key>
                        <dict>
                            <key>UUID</key>
                            <string>{$e($dictateUuid)}</string>
                            <key>CustomOutputName</key>
                            <string>{$e($outputName)}</string>
                        </dict>
                    </dict>
                    <dict>
                        <key>WFWorkflowActionIdentifier</key>
                        <string>is.workflow.actions.downloadurl</string>
                        <key>WFWorkflowActionParameters</key>
                        <dict>
                            <key>WFURL</key>
                            <string>{$e($endpoint)}</string>
                            <key>WFHTTPMethod</key>
                            <string>POST</string>
                            <key>WFHTTPHeaders</key>
                            <dict>
                                <key>Value</key>
                                <dict>
                                    <key>WFDictionaryFieldValueItems</key>
                                    <array>
                                        <dict>
                                            <key>WFItemType</key>
                                            <integer>0</integer>
                                            <key>WFKey</key>
                                            <dict>
                                                <key>Value</key>
                                                <dict>
                                                    <key>string</key>
                                                    <string>Authorization</string>
                                                </dict>
                                                <key>WFSerializationType</key>
                                                <string>WFTextTokenString</string>
                                            </dict>
                                            <key>WFValue</key>
                                            <dict>
                                                <key>Value</key>
                                                <dict>
                                                    <key>string</key>
                                                    <string>Bearer {$e($token)}</string>
                                                </dict>
                                                <key>WFSerializationType</key>
                                                <string>WFTextTokenString</string>
                                            </dict>
                                        </dict>
                                        <dict>
                                            <key>WFItemType</key>
                                            <integer>0</integer>
                                            <key>WFKey</key>
                                            <dict>
                                                <key>Value</key>
                                                <dict>
                                                    <key>string</key>
                                                    <string>Accept</string>
                                                </dict>
                                                <key>WFSerializationType</key>
                                                <string>WFTextTokenString</string>
                                            </dict>
                                            <key>WFValue</key>
                                            <dict>
                                                <key>Value</key>
                                                <dict>
                                                    <key>string</key>
                                                    <string>application/json</string>
                                                </dict>
                                                <key>WFSerializationType</key>
                                                <string>WFTextTokenString</string>
                                            </dict>
                                        </dict>
                                    </array>
                                </dict>
                                <key>WFSerializationType</key>
                                <string>WFDictionaryFieldValue</string>
                            </dict>
                            <key>WFHTTPBodyType</key>
                            <string>JSON</string>
                            <key>WFJSONValues</key>
                            <dict>
                                <key>Value</key>
                                <dict>
                                    <key>WFDictionaryFieldValueItems</key>
                                    <array>
                                        <dict>
                                            <key>WFItemType</key>
                                            <integer>0</integer>
                                            <key>WFKey</key>
                                            <dict>
                                                <key>Value</key>
                                                <dict>
                                                    <key>string</key>
                                                    <string>text</string>
                                                </dict>
                                                <key>WFSerializationType</key>
                                                <string>WFTextTokenString</string>
                                            </dict>
                                            <key>WFValue</key>
                                            <dict>
                                                <key>Value</key>
                                                <dict>
                                                    <key>string</key>
                                                    <string>{$placeholder}</string>
                                                    <key>attachmentsByRange</key>
                                                    <dict>
                                                        <key>{0, 1}</key>
                                                        <dict>
                                                            <key>OutputName</key>
                                                            <string>{$e($outputName)}</string>
                                                            <key>OutputUUID</key>
                                                            <string>{$e($dictateUuid)}</string>
                                                            <key>Type</key>
                                                            <string>ActionOutput</string>
                                                        </dict>
                                                    </dict>
                                                </dict>
                                                <key>WFSerializationType</key>
                                                <string>WFTextTokenString</string>
                                            </dict>
                                        </dict>
                                    </array>
                                </dict>
                                <key>WFSerializationType</key>
                                <string>WFDictionaryFieldValue</string>
                            </dict>
                        </dict>
                    </dict>
                    <dict>
                        <key>WFWorkflowActionIdentifier</key>
                        <string>is.workflow.actions.showresult</string>
                        <key>WFWorkflowActionParameters</key>
                        <dict>
                            <key>Text</key>
                            <dict>
                                <key>Value</key>
                                <dict>
                                    <key>string</key>
                                    <string>{$e(__('Added to the shopping list.'))}</string>
                                </dict>
                                <key>WFSerializationType</key>
                                <string>WFTextTokenString</string>
                            </dict>
                        </dict>
                    </dict>
                </array>
                <key>WFWorkflowName</key>
                <string>{$e($name)}</string>
            </dict>
            </plist>
            PLIST;
    }
}
