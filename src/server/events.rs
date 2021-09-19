use std::time::SystemTime;
use byteorder::{WriteBytesExt, BigEndian};
use crate::Result;
use std::io::Write;

pub enum Event {
    SocketConnectError(String),
    Heartbeat,
    SocketSendError,
    AddUserData(String),
    RemoveUserData(String),
    BanUser(String, String, Option<SystemTime>),
    ResetAllPlayerData,
    PlayerSendPacket(String, Packet, f64),
    ServerSendPacket(String, BatchPacket, f64),
    LagCompensation(String, Packet, f64),
    InitData(Vec<ExtraData>),
    AlertNotification(String, BatchPacket),
    CommandRequest(String, String, Vec<String>),
    CommandResponse(String, String),
    Unknown
}

enum ExtraData{}

//Place holder for the protocol lib to be made.
enum Packet{}

impl Packet {
    pub fn encode() -> &[u8] {
        return &[]
    }
}
enum BatchPacket{}
impl BatchPacket {
    pub fn encode() -> &[u8] {
        return &[]
    }
}

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
            Event::AlertNotification(_alertType, _alertPacket) => "server:alert_notification",
            Event::CommandRequest(_sender, _commandType, _args) => "command:request",
            Event::CommandResponse(_target, _response) => "command:response",
            _ => "unknown"
        }.to_string()
    }
}

impl Event {

    pub fn encode(&self, buf: &mut Vec<u8>) -> Result<()> {
        buf.write_all(self.to_string().as_bytes())?;
        match self {
            Event::SocketConnectError(msg) => {
                buf.write_all(msg.as_bytes())?;
            }
            Event::AddUserData(identifier) => {
                buf.write_all(identifier.as_bytes())?;
            }
            Event::RemoveUserData(identifier) => {
                buf.write_all(identifier.as_bytes())?;
            }
            Event::BanUser(username, reason, expiration) => {
                buf.write_all(username.as_bytes())?;
                buf.write_all(reason.as_bytes())?;
                //Work out a way to write a SystemTime to a DateTime
            }
            Event::PlayerSendPacket(identifier, packet, timestamp) => {
                buf.write_all(identifier.as_bytes())?;
                buf.write_all(packet.encode())?;
                buf.write_f64(*timestamp)?;
            }
            Event::ServerSendPacket(identifier, packet, timestamp) => {
                buf.write_all(identifier.as_bytes())?;
                buf.write_all(packet.encode())?;
                buf.write_f64::<BigEndian>(*timestamp)?;
            }
            Event::LagCompensation(identifier, packet, timestamp) => {
                buf.write_all(identifier.as_bytes())?;
                buf.write_all(packet.encode())?;
                buf.write_f64::<BigEndian>(*timestamp)?;
            }
            Event::InitData(extraData) => {
                //encode legit nothing as we don't support it yet.
            }
            Event::AlertNotification(alertType, alertPacket) => {
                buf.write_all(alertPacket.as_bytes())?;
                buf.write_all(alertPacket.encode())?;
            }
            Event::CommandRequest(sender, commandType, args) => {
                buf.write_all(sender.as_bytes())?;
                buf.write_all(commandType.as_bytes())?;
                //We can't really do shit with args yet as i bet its serialized.
            }
            Event::CommandResponse(target, response) => {
                buf.write_all(target.as_bytes())?;
                buf.write_all(response.as_bytes())?;
            }
            //There is no extra parameters we don't have to encode anything else.
            _ => {}
        }
        Ok(())
    }
}