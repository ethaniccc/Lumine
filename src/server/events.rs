use crate::Result;
use byteorder::WriteBytesExt;
use lumine_protocol::CanIo;
use std::io::Write;
use std::time::SystemTime;

pub enum Event {
    SocketConnectError(String),
    Heartbeat,
    SocketSendError,
    AddUserData(String),
    RemoveUserData(String),
    BanUser(String, String, Option<SystemTime>),
    ResetAllPlayerData,
    PlayerSendPacket(String, lumine_protocol::Packet, f64),
    ServerSendPacket(String, BatchPacket, f64),
    LagCompensation(String, lumine_protocol::Packet, f64),
    InitData(Vec<ExtraData>),
    AlertNotification(String, BatchPacket),
    CommandRequest(String, String, Vec<String>),
    CommandResponse(String, String),
    Unknown,
}
//Another place holder
enum ExtraData {}
//Just a place holder
enum BatchPacket {}

impl ToString for Event {
    fn to_string(&self) -> String {
        match self {
            Event::SocketConnectError(_message) => "thread:connect_error",
            Event::Heartbeat => "socket:heartbeat",
            Event::SocketSendError => "socket:send_error",
            Event::AddUserData(_identifier) => "socket:add_user",
            Event::RemoveUserData(_identifier) => "socket:remove_user",
            Event::BanUser(_username, _reason, _expiration) => "socket:ban_user",
            Event::ResetAllPlayerData => "socket:reset_data",
            Event::PlayerSendPacket(_identifier, _packet, _timestamp) => "player:send_packet",
            Event::ServerSendPacket(_identifier, _packet, _timestamp) => "server:send_packet",
            Event::LagCompensation(_identifier, _packet, _timestamp) => "player:lag_compensation",
            Event::InitData(_data) => "socket:init_data",
            Event::AlertNotification(_alert_type, _alert_packet) => "server:alert_notification",
            Event::CommandRequest(_sender, _command_type, _args) => "command:request",
            Event::CommandResponse(_target, _response) => "command:response",
            _ => "unknown",
        }
        .to_string()
    }
}

impl Event {
    pub fn encode(&self, buf: &mut Vec<u8>) -> Result<()> {
        //This might be possibly changed to byte ids will know soon. if this happens i will make this into another can_io enum for packets.
        buf.write_all(self.to_string().as_bytes())?;
        match self {
            Event::SocketConnectError(msg) => {
                msg.write(buf);
            }
            Event::AddUserData(identifier) => {
                identifier.write(buf);
            }
            Event::RemoveUserData(identifier) => {
                identifier.write(buf);
            }
            Event::BanUser(username, reason, expiration) => {
                username.write(buf);
                reason.write(buf);
                //Work out a way to write a SystemTime to a DateTime
            }
            Event::PlayerSendPacket(identifier, packet, timestamp) => {
                identifier.write(buf);
                packet.write(buf);
                timestamp.write(buf);
            }
            Event::ServerSendPacket(identifier, packet, timestamp) => {
                identifier.write(buf);
                timestamp.write(buf);
            }
            Event::LagCompensation(identifier, packet, timestamp) => {
                identifier.write(buf);
                packet.write(buf);
                timestamp.write(buf);
            }
            Event::InitData(extra_data) => {
                //encode legit nothing as we don't support it yet.
            }
            Event::AlertNotification(alert_type, alert_packet) => {
                alert_type.write(buf);
            }
            Event::CommandRequest(sender, command_type, args) => {
                sender.write(buf);
                command_type.write(buf);
                //We can't really do shit with args yet as i bet its serialized.
                args.write(buf);
            }
            Event::CommandResponse(target, response) => {
                target.write(buf);
                response.write(buf);
            }
            //There is no extra parameters we don't have to encode anything else.
            _ => {}
        }
        Ok(())
    }
}
